<?php
namespace App\Services;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
final class DashboardService {
    private array $tables=[];
    private function hasTable(string $table): bool {
        if (!array_key_exists($table,$this->tables)) {
            $pdo=Database::connection();
            $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
                ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?"
                : 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?';
            $stmt=$pdo->prepare($sql);$stmt->execute([$table]);$this->tables[$table]=(bool)$stmt->fetchColumn();
        }
        return $this->tables[$table];
    }
    private function query(string $sql,array $args): \PDOStatement { $stmt=Database::connection()->prepare($sql);$stmt->execute($args);return $stmt; }
    public function overview(array $user,int $days): array {
        $owner=$user['role']==='shop_owner';$shop=$user['shop_id'];
        $access=[
            'catalogue'=>$shop && ($owner || can($user,'products.manage') || can($user,'reports.view')),
            'stock'=>$shop && ($owner || can($user,'inventory.view') || can($user,'inventory.adjust') || can($user,'products.manage') || can($user,'reports.view')),
            'finance'=>$shop && ($owner || can($user,'reports.view')),
        ];
        $data=['access'=>$access,'products'=>null,'low_stock_count'=>null,'valuation'=>null,'balance_count'=>0,'untracked_count'=>0,'category_count'=>null,'subcategory_count'=>null,'low_stock'=>[],'movements'=>[],'activity'=>[],'sales_today'=>null,'revenue'=>null,'recent_sales'=>[],'available'=>[],'errors'=>[]];
        if (!$shop) return $data;
        $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Kolkata'));
        $from=$today->modify('-'.($days-1).' days')->format('Y-m-d H:i:s');$until=$today->modify('+1 day')->format('Y-m-d H:i:s');
        try {
            if ($access['catalogue'] && ($data['available']['products']=$this->hasTable('products'))) {
                $data['products']=(int)$this->query("SELECT COUNT(*) FROM products WHERE shop_id=? AND status='active' AND archived_at IS NULL",[$shop])->fetchColumn();
            }
            if ($owner && $this->hasTable('categories') && $this->hasTable('subcategories')) {
                $data['category_count']=(int)$this->query('SELECT COUNT(*) FROM categories WHERE shop_id=? AND archived_at IS NULL',[$shop])->fetchColumn();
                $data['subcategory_count']=(int)$this->query('SELECT COUNT(*) FROM subcategories WHERE shop_id=? AND archived_at IS NULL',[$shop])->fetchColumn();
            }
        } catch (Throwable $e) { error_log($e->getMessage());$data['products']=null;$data['category_count']=null;$data['subcategory_count']=null;$data['errors']['catalogue']='Catalogue totals are temporarily unavailable.'; }
        try {
            if (($access['stock'] || $access['finance']) && ($data['available']['stock']=$this->hasTable('products') && $this->hasTable('stock_balances'))) {
                if ($access['stock']) {
                    $where="FROM products p JOIN stock_balances b ON b.shop_id=p.shop_id AND b.product_id=p.id WHERE p.shop_id=? AND p.status='active' AND p.archived_at IS NULL AND b.quantity>0 AND p.reorder_level>0 AND b.quantity<=p.reorder_level";
                    $data['low_stock_count']=(int)$this->query('SELECT COUNT(*) '.$where,[$shop])->fetchColumn();
                    $data['low_stock']=$this->query('SELECT p.name,p.sku,b.quantity,p.reorder_level '.$where.' ORDER BY b.quantity/p.reorder_level,p.name LIMIT 6',[$shop])->fetchAll();
                }
                $data['balance_count']=(int)$this->query('SELECT COUNT(*) FROM stock_balances WHERE shop_id=?',[$shop])->fetchColumn();
                $data['untracked_count']=(int)$this->query("SELECT COUNT(*) FROM products p LEFT JOIN stock_balances b ON b.shop_id=p.shop_id AND b.product_id=p.id WHERE p.shop_id=? AND p.status='active' AND p.archived_at IS NULL AND b.product_id IS NULL",[$shop])->fetchColumn();
                if ($access['finance']) $data['valuation']=$this->query('SELECT COALESCE(SUM(quantity*average_unit_cost),0) FROM stock_balances WHERE shop_id=?',[$shop])->fetchColumn();
            }
        } catch (Throwable $e) { error_log($e->getMessage());$data['low_stock_count']=null;$data['valuation']=null;$data['errors']['stock']='Stock information is temporarily unavailable.'; }
        try {
            if ($access['stock'] && ($owner || can($user,'inventory.movements') || can($user,'products.manage') || can($user,'reports.view')) && ($data['available']['movements']=$this->hasTable('stock_movements') && $this->hasTable('products') && $this->hasTable('units'))) {
                $data['movements']=$this->query('SELECT p.name,p.sku,m.movement_type,m.quantity_delta,m.created_at,m.reference_type,m.reference_id,u.code AS unit FROM stock_movements m JOIN products p ON p.shop_id=m.shop_id AND p.id=m.product_id JOIN units u ON u.shop_id=p.shop_id AND u.id=p.base_unit_id WHERE m.shop_id=? AND m.created_at>=? AND m.created_at<? ORDER BY m.created_at DESC,m.id DESC LIMIT 6',[$shop,$from,$until])->fetchAll();
            }
        } catch (Throwable $e) { error_log($e->getMessage());$data['errors']['movements']='Recent stock movements could not be loaded.'; }
        try {
            $sql='SELECT name,role,last_login_at FROM users WHERE shop_id=? AND last_login_at>=? AND last_login_at<?';$args=[$shop,$from,$until];
            if (!$owner) { $sql.=' AND id=?';$args[]=$user['id']; }
            $data['activity']=$this->query($sql.' ORDER BY last_login_at DESC,id DESC LIMIT 5',$args)->fetchAll();
        } catch (Throwable $e) { error_log($e->getMessage());$data['errors']['activity']='Recent activity could not be loaded.'; }
        try {
            if ($access['finance'] && $this->hasTable('sales') && $this->hasTable('sale_items') && $this->hasTable('sale_services') && $this->hasTable('service_expenses') && $this->hasTable('sale_cancellations')) {
                $revenue=new RevenueReportService((int)$shop);
                $data['sales_today']=$revenue->summary(['from'=>$today->format('Y-m-d'),'to'=>$today->format('Y-m-d')]);
                $data['revenue']=$revenue->summary(['from'=>substr($from,0,10),'to'=>$today->format('Y-m-d')]);
                if (can($user,'sales.view')) $data['recent_sales']=array_slice((new SaleService((int)$shop))->history([])['rows'],0,5);
            }
        } catch (Throwable $e) { error_log($e->getMessage());$data['sales_today']=null;$data['revenue']=null;$data['errors']['sales']='Sales totals are temporarily unavailable.'; }
        return $data;
    }
}
