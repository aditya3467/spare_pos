<?php
namespace App\Services;
use App\Core\Database;
use DomainException;
use PDO;
use PDOException;
use Throwable;
final class CategoryService {
    public function __construct(private int $shopId) {}
    private function db(): PDO { return Database::connection(); }
    private function transaction(callable $action): mixed {
        $pdo = $this->db();
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM shops WHERE id = ? FOR UPDATE');
            $lock->execute([$this->shopId]);
            if (!$lock->fetchColumn()) throw new DomainException('Shop not found.');
            $result = $action(); $pdo->commit(); return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) throw new DomainException('That name or slug is already in use, including archived entries.');
            if ($e instanceof PDOException && in_array($e->errorInfo[1] ?? null, [1451,1452], true)) throw new DomainException('This change would break an existing product or category reference.');
            throw $e;
        }
    }
    private function table(string $level): string {
        return match ($level) { 'category' => 'categories', 'subcategory' => 'subcategories', default => throw new DomainException('Invalid category level.') };
    }
    public static function slug(string $name): string {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return $slug ?: 'category-' . substr(hash('sha256', $name), 0, 12);
    }
    private function entry(string $table, int $id, bool $active = false): array {
        $stmt = $this->db()->prepare("SELECT * FROM $table WHERE id = ? AND shop_id = ? AND archived_at IS NULL FOR UPDATE");
        $stmt->execute([$id, $this->shopId]); $row = $stmt->fetch();
        if (!$row || ($active && $row['status'] !== 'active')) throw new DomainException('Choose a valid, active destination in your shop.');
        return $row;
    }
    public function all(bool $includeArchived = false): array {
        $categoryArchive=$includeArchived?'':' AND c.archived_at IS NULL';
        $subArchive=$includeArchived?'':' AND s.archived_at IS NULL';
        $stmt = $this->db()->prepare('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.shop_id=c.shop_id AND p.category_id=c.id) AS product_count, (SELECT COUNT(*) FROM products p WHERE p.shop_id=c.shop_id AND p.category_id=c.id AND p.subcategory_id IS NULL) AS direct_product_count FROM categories c WHERE c.shop_id=?'.$categoryArchive.' ORDER BY c.sort_order,c.name,c.id');
        $stmt->execute([$this->shopId]); $categories = $stmt->fetchAll();
        $stmt = $this->db()->prepare('SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.shop_id=s.shop_id AND p.subcategory_id=s.id) AS product_count FROM subcategories s WHERE s.shop_id=?'.$subArchive.' ORDER BY s.sort_order,s.name,s.id');
        $stmt->execute([$this->shopId]); $children=[];
        foreach ($stmt->fetchAll() as $row) $children[$row['category_id']][]=$row;
        foreach ($categories as &$row) $row['subcategories']=$children[$row['id']] ?? [];
        return $categories;
    }
    public function save(string $level, ?int $id, array $data): int {
        $table=$this->table($level);
        $name=trim($data['name'] ?? ''); $description=trim($data['description'] ?? '');
        $slug=trim($data['slug'] ?? '') ?: self::slug($name);
        $status=$data['status'] ?? 'active';
        $order=filter_var($data['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name)>150 || mb_strlen($description)>5000 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || strlen($slug)>190 || !in_array($status,['active','inactive'],true) || $order===false || $order<0 || $order>1000000) throw new DomainException('Enter a name (up to 150 characters), a valid slug, status and sort order (0–1,000,000).');
        return $this->transaction(function () use ($table,$level,$id,$data,$name,$slug,$description,$status,$order) {
            $existing=$id ? $this->entry($table,$id) : null;
            $parent=null;
            if ($level==='subcategory') {
                $parent=filter_var($data['category_id'] ?? '',FILTER_VALIDATE_INT);
                if (!$parent) throw new DomainException('Choose a parent category.');
                $this->entry('categories',$parent);
                if ($existing && (int)$existing['category_id']!==$parent) throw new DomainException('Use move / merge to change the parent safely.');
            }
            if ($id) {
                $stmt=$this->db()->prepare("UPDATE $table SET name=?,slug=?,description=?,status=?,sort_order=? WHERE id=? AND shop_id=?");
                $stmt->execute([$name,$slug,$description,$status,$order,$id,$this->shopId]); return $id;
            }
            $columns='shop_id,name,slug,description,status,sort_order';$values=[$this->shopId,$name,$slug,$description,$status,$order];
            if ($parent) { $columns.=',category_id';$values[]=$parent; }
            $stmt=$this->db()->prepare("INSERT INTO $table ($columns) VALUES (".implode(',',array_fill(0,count($values),'?')).")");
            $stmt->execute($values);return (int)$this->db()->lastInsertId();
        });
    }
    public function reorder(string $level,int $id,string $direction): void {
        $table=$this->table($level);
        if (!in_array($direction,['up','down'],true)) throw new DomainException('Invalid order direction.');
        $this->transaction(function () use($table,$id,$direction) {
            $entry=$this->entry($table,$id);
            $query="SELECT id FROM $table WHERE shop_id=? AND archived_at IS NULL";$args=[$this->shopId];
            if ($table==='subcategories') {$query.=' AND category_id=?';$args[]=$entry['category_id'];}
            $stmt=$this->db()->prepare($query.' ORDER BY sort_order,name,id FOR UPDATE');$stmt->execute($args);
            $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));$i=array_search($id,$ids,true);$j=$i+($direction==='up'?-1:1);
            if (isset($ids[$j])) [$ids[$i],$ids[$j]]=[$ids[$j],$ids[$i]];
            $update=$this->db()->prepare("UPDATE $table SET sort_order=? WHERE id=? AND shop_id=?");
            foreach ($ids as $i=>$entryId) $update->execute([($i+1)*10,$entryId,$this->shopId]);
        });
    }
    private function destination(int $categoryId,?int $subcategoryId): void {
        $this->entry('categories',$categoryId,true);
        if ($subcategoryId) {
            $sub=$this->entry('subcategories',$subcategoryId,true);
            if ((int)$sub['category_id']!==$categoryId) throw new DomainException('The selected subcategory does not belong to that category.');
        }
    }
    public function archiveSubcategory(int $id,?int $categoryId=null,?int $subcategoryId=null): void {
        $this->transaction(function () use($id,$categoryId,$subcategoryId) {
            $this->entry('subcategories',$id);
            if ($subcategoryId===$id) throw new DomainException('Choose a different destination.');
            if ($categoryId) {
                $this->destination($categoryId,$subcategoryId);
                $stmt=$this->db()->prepare('UPDATE products SET category_id=?,subcategory_id=? WHERE shop_id=? AND subcategory_id=?');
                $stmt->execute([$categoryId,$subcategoryId,$this->shopId,$id]);
            }
            $stmt=$this->db()->prepare('SELECT COUNT(*) FROM products WHERE shop_id=? AND subcategory_id=?');$stmt->execute([$this->shopId,$id]);
            if ($stmt->fetchColumn()) throw new DomainException('Products still reference this subcategory. Choose a destination first.');
            $this->db()->prepare("UPDATE subcategories SET archived_at=NOW(),status='inactive' WHERE id=? AND shop_id=?")->execute([$id,$this->shopId]);
        });
    }
    public function archiveCategory(int $id): void {
        $this->transaction(function () use($id) {
            $this->entry('categories',$id);
            $stmt=$this->db()->prepare('SELECT (SELECT COUNT(*) FROM products WHERE shop_id=? AND category_id=?) + (SELECT COUNT(*) FROM subcategories WHERE shop_id=? AND category_id=? AND archived_at IS NULL)');$stmt->execute([$this->shopId,$id,$this->shopId,$id]);
            if ($stmt->fetchColumn()) throw new DomainException('Move or archive all subcategories and reassign all products first, or use merge.');
            $this->db()->prepare("UPDATE categories SET archived_at=NOW(),status='inactive' WHERE id=? AND shop_id=?")->execute([$id,$this->shopId]);
        });
    }
    public function mergeCategory(int $id,int $target): void {
        if ($id===$target) throw new DomainException('Choose a different destination category.');
        $this->transaction(function () use($id,$target) {
            $this->entry('categories',$id);$this->entry('categories',$target,true);
            $stmt=$this->db()->prepare('SELECT * FROM subcategories WHERE shop_id=? AND category_id=? AND archived_at IS NULL FOR UPDATE');$stmt->execute([$this->shopId,$id]);
            foreach ($stmt->fetchAll() as $sub) $this->moveSubcategory($sub,$target);
            $this->db()->prepare('UPDATE products SET category_id=?,subcategory_id=NULL WHERE shop_id=? AND category_id=?')->execute([$target,$this->shopId,$id]);
            $this->db()->prepare("UPDATE categories SET archived_at=NOW(),status='inactive' WHERE id=? AND shop_id=?")->execute([$id,$this->shopId]);
        });
    }
    private function matchingSubcategory(int $target,string $name): array|false {
        $stmt=$this->db()->prepare('SELECT * FROM subcategories WHERE shop_id=? AND category_id=? AND name=? FOR UPDATE');
        $stmt->execute([$this->shopId,$target,$name]);return $stmt->fetch();
    }
    public function mergePreview(int $id,int $target): array {
        if ($id===$target) throw new DomainException('Choose a different destination category.');
        return $this->transaction(function () use($id,$target) {
            $source=$this->entry('categories',$id);$destination=$this->entry('categories',$target,true);
            $stmt=$this->db()->prepare('SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.shop_id=s.shop_id AND p.subcategory_id=s.id) AS product_count FROM subcategories s WHERE shop_id=? AND category_id=? AND archived_at IS NULL ORDER BY sort_order,id');$stmt->execute([$this->shopId,$id]);
            $rows=[];$blocked=false;
            foreach ($stmt->fetchAll() as $sub) {
                $match=$this->matchingSubcategory($target,$sub['name']);$reason=null;
                if ($match && ($match['archived_at'] || $match['status']!=='active')) $reason='Matching destination is inactive or archived. Restore or activate it first.';
                if (!$match) {
                    $slug=$this->db()->prepare('SELECT COUNT(*) FROM subcategories WHERE shop_id=? AND category_id=? AND slug=?');$slug->execute([$this->shopId,$target,$sub['slug']]);
                    if ($slug->fetchColumn()) $reason='Destination slug is already in use. Edit the slug before merging.';
                }
                $blocked=$blocked || $reason!==null;
                $rows[]=['name'=>$sub['name'],'products'=>(int)$sub['product_count'],'action'=>$match?'Merge matching name':'Move into destination','reason'=>$reason];
            }
            $count=$this->db()->prepare('SELECT COUNT(*) AS total, SUM(subcategory_id IS NULL) AS direct_count FROM products WHERE shop_id=? AND category_id=?');$count->execute([$this->shopId,$id]);$counts=$count->fetch();
            return ['source'=>$source['name'],'destination'=>$destination['name'],'products'=>(int)$counts['total'],'direct_products'=>(int)$counts['direct_count'],'subcategories'=>count($rows),'rows'=>$rows,'blocked'=>$blocked];
        });
    }
    // Copy or merge into the destination before moving products, preserving restrictive FKs.
    private function moveSubcategory(array $sub,int $target): void {
        $existing=$this->matchingSubcategory($target,$sub['name']);
        if ($existing) {
            if ($existing['archived_at'] || $existing['status']!=='active') throw new DomainException('A matching destination subcategory is inactive or archived. Rename it or activate it before merging.');
            $newId=$existing['id'];
        } else {
            $stmt=$this->db()->prepare("INSERT INTO subcategories (shop_id,category_id,name,slug,description,status,sort_order) VALUES (?,?,?,?,?,'active',?)");
            $stmt->execute([$this->shopId,$target,$sub['name'],$sub['slug'],$sub['description'],$sub['sort_order']]);$newId=(int)$this->db()->lastInsertId();
        }
        $this->db()->prepare('UPDATE products SET category_id=?,subcategory_id=? WHERE shop_id=? AND subcategory_id=?')->execute([$target,$newId,$this->shopId,$sub['id']]);
        $this->db()->prepare("UPDATE subcategories SET archived_at=NOW(),status='inactive' WHERE shop_id=? AND id=?")->execute([$this->shopId,$sub['id']]);
    }
    public function restore(string $level,int $id): void {
        $table=$this->table($level);
        $this->transaction(function () use($table,$id) {
            $stmt=$this->db()->prepare("SELECT * FROM $table WHERE shop_id=? AND id=? FOR UPDATE");$stmt->execute([$this->shopId,$id]);$row=$stmt->fetch();
            if (!$row || !$row['archived_at']) throw new DomainException('Choose an archived entry in your shop.');
            if ($table==='subcategories') $this->entry('categories',(int)$row['category_id']);
            $this->db()->prepare("UPDATE $table SET archived_at=NULL,status='active' WHERE shop_id=? AND id=?")->execute([$this->shopId,$id]);
        });
    }
    public function deleteSafely(string $level,int $id): void {
        $table=$this->table($level);
        $this->transaction(function () use($table,$id) {
            $stmt=$this->db()->prepare("SELECT archived_at FROM $table WHERE shop_id=? AND id=? FOR UPDATE");$stmt->execute([$this->shopId,$id]);$row=$stmt->fetch();
            if (!$row || !$row['archived_at']) throw new DomainException('Archive this entry first. Permanent deletion is only available for unreferenced archived entries.');
            $column=$table==='categories'?'category_id':'subcategory_id';
            $stmt=$this->db()->prepare("SELECT COUNT(*) FROM products WHERE shop_id=? AND $column=?");$stmt->execute([$this->shopId,$id]);
            if ($stmt->fetchColumn()) throw new DomainException('Reassign linked products before deletion.');
            if ($table==='categories') {
                $stmt=$this->db()->prepare('SELECT COUNT(*) FROM subcategories WHERE shop_id=? AND category_id=?');$stmt->execute([$this->shopId,$id]);
                if ($stmt->fetchColumn()) throw new DomainException('Move or safely delete all subcategories, including archived entries, first.');
            }
            // Restrictive foreign keys also reject any historical references.
            try { $this->db()->prepare("DELETE FROM $table WHERE shop_id=? AND id=?")->execute([$this->shopId,$id]); }
            catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null)===1451) throw new DomainException('Deletion blocked by an existing product or historical reference. Keep this entry archived.');
                throw $e;
            }
        });
    }
    public function relocateSubcategory(int $id,int $targetCategory): void {
        $this->transaction(function () use($id,$targetCategory) {
            $sub=$this->entry('subcategories',$id);$this->entry('categories',$targetCategory,true);
            if ((int)$sub['category_id']===$targetCategory) throw new DomainException('Choose a different main category.');
            $this->moveSubcategory($sub,$targetCategory);
        });
    }
    public function mergeSubcategory(int $id,int $target): void {
        $stmt=$this->db()->prepare('SELECT category_id FROM subcategories WHERE shop_id=? AND id=?');$stmt->execute([$this->shopId,$target]);
        $category=$stmt->fetchColumn();
        if (!$category) throw new DomainException('Choose a valid target subcategory.');
        $this->archiveSubcategory($id,(int)$category,$target);
    }
    public function validateProductCategory(int $categoryId, ?int $subcategoryId = null): void {
        $this->transaction(fn() => $this->destination($categoryId, $subcategoryId));
    }
    public function seed(): void {
        $catalogue=require BASE_PATH.'/database/seeders/categories.php';
        $this->transaction(function () use($catalogue) {
            foreach ($catalogue as $indexName=>$children) {
                $stmt=$this->db()->prepare('SELECT id FROM categories WHERE shop_id=? AND name=?');$stmt->execute([$this->shopId,$indexName]);$id=$stmt->fetchColumn();
                if (!$id) {
                    $this->db()->prepare('INSERT INTO categories (shop_id,name,slug,sort_order) VALUES (?,?,?,?)')->execute([$this->shopId,$indexName,self::slug($indexName),(array_search($indexName,array_keys($catalogue),true)+1)*10]);$id=$this->db()->lastInsertId();
                }
                foreach ($children as $i=>$name) {
                    $stmt=$this->db()->prepare('SELECT id FROM subcategories WHERE shop_id=? AND category_id=? AND name=?');$stmt->execute([$this->shopId,$id,$name]);
                    if (!$stmt->fetchColumn()) $this->db()->prepare('INSERT INTO subcategories (shop_id,category_id,name,slug,sort_order) VALUES (?,?,?,?,?)')->execute([$this->shopId,$id,$name,self::slug($name),($i+1)*10]);
                }
            }
        });
    }
}
