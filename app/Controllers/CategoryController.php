<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Services\CategoryService;
use DomainException;
final class CategoryController extends Controller {
    private function service(): CategoryService { return new CategoryService((int)current_user()['shop_id']); }
    public function index(): void {
        if (($_GET['preview'] ?? '')==='merge') {
            header('Content-Type: application/json');
            try {
                $id=filter_var($_GET['id'] ?? '',FILTER_VALIDATE_INT);$target=filter_var($_GET['target_id'] ?? '',FILTER_VALIDATE_INT);
                if (!$id || !$target) throw new DomainException('Choose a valid source and destination.');
                echo json_encode($this->service()->mergePreview($id,$target));
            } catch (DomainException $e) { http_response_code(422);echo json_encode(['message'=>$e->getMessage()]); }
            return;
        }
        $categories=$this->service()->all(true);
        $notice=$_SESSION['category_notice'] ?? ''; unset($_SESSION['category_notice']);
        $error=$_SESSION['category_error'] ?? ''; unset($_SESSION['category_error']);
        $old=$_SESSION['category_old'] ?? []; unset($_SESSION['category_old']);
        $filters=['q'=>is_string($_GET['q'] ?? null)?mb_substr(trim($_GET['q']),0,150):'', 'status'=>in_array($_GET['status'] ?? '',['active','archived','all'],true)?$_GET['status']:'active', 'view'=>in_array($_GET['view'] ?? '',['category','subcategory'],true)?$_GET['view']:'all'];
        $data=compact('categories','notice','error','old','filters');
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '','application/json')) {
            header('Content-Type: application/json');echo json_encode(['html'=>$this->render('categories/index',$data),'message'=>$notice]);return;
        }
        $this->page('categories/index',$data,'Categories','Catalogue');
    }
    public function mutate(): void {
        try {
            foreach ($_POST as $value) if (!is_string($value)) throw new DomainException('Invalid form data.');
            $action=$_POST['action'] ?? '';
            $level=$_POST['level'] ?? 'category';
            $id=filter_var($_POST['id'] ?? '',FILTER_VALIDATE_INT);
            if ($action!=='save' && (!$id || $id<1)) throw new DomainException('Choose a valid category.');
            $service=$this->service();
            switch ($action) {
                case 'save':
                    if (isset($_POST['id']) && (!$id || $id<1)) throw new DomainException('Invalid category ID.');
                    $service->save($level,$id ?: null,$_POST); $notice='Category saved.'; break;
                case 'restore':
                    $service->restore($level,$id);$notice='Restored successfully.';break;
                case 'delete':
                    $service->deleteSafely($level,$id);$notice='Unreferenced archived entry deleted safely.';break;
                case 'move_subcategory':
                case 'merge_subcategory':
                    $target=filter_var($_POST['target_id'] ?? '',FILTER_VALIDATE_INT);
                    if (!$target || $target<1) throw new DomainException('Choose a valid destination.');
                    if ($action==='move_subcategory') $service->relocateSubcategory($id,$target);
                    else $service->mergeSubcategory($id,$target);
                    $notice='Subcategory moved / merged safely. Original references were preserved.';break;
                case 'reorder':
                    $service->reorder($level,$id,$_POST['direction'] ?? ''); $notice='Order updated.'; break;
                case 'archive':
                    if ($level==='category') $service->archiveCategory($id);
                    elseif ($level==='subcategory') {
                        $destination=$_POST['destination'] ?? '';
                        if ($destination!=='' && !preg_match('/^[1-9][0-9]*:[0-9]+$/D',$destination)) throw new DomainException('Choose a valid destination.');
                        [$category,$sub]=$destination!=='' ? array_map('intval',explode(':',$destination)) : [null,null];
                        $service->archiveSubcategory($id,$category,$sub ?: null);
                    } else throw new DomainException('Invalid level.');
                    $notice='Archived safely. Product records and history were preserved.'; break;
                case 'merge':
                    $target=filter_var($_POST['target_id'] ?? '',FILTER_VALIDATE_INT);
                    if (!$target || $target<1) throw new DomainException('Choose a destination category.');
                    $service->mergeCategory($id,$target); $notice='Categories merged. All products were preserved.'; break;
                default: throw new DomainException('Invalid category action.');
            }
            $_SESSION['category_notice']=$notice;
        } catch (DomainException $e) {
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '','application/json')) {
                http_response_code(422);header('Content-Type: application/json');echo json_encode(['message'=>$e->getMessage()]);return;
            }
            $_SESSION['category_error']=$e->getMessage();
            $_SESSION['category_old']=array_intersect_key(array_filter($_POST,'is_string'),array_flip(['action','level','id','category_id','name','slug','description','status','sort_order']));
        }
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '','application/json')) { $this->index();return; }
        redirect('/categories');
    }
}
