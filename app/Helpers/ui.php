<?php
function icon(string $name, string $class = ''): string {
    $paths = [
        'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'box'=>'<path d="m3 7 9-4 9 4v10l-9 4-9-4Z"/><path d="m3 7 9 4 9-4M12 11v10M7.5 5l9 4"/>',
        'folder'=>'<path d="M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11H3Z"/>',
        'layers'=>'<path d="m3 7 9-4 9 4-9 4ZM3 12l9 4 9-4M3 17l9 4 9-4"/>',
        'stock'=>'<path d="M3 21h18M5 21V10h14v11M3 10l9-7 9 7M9 14h6v7M9 10h6"/>',
        'arrows'=>'<path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4"/>',
        'sliders'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
        'truck'=>'<path d="M3 5h12v12H3ZM15 9h4l3 4v4h-7"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        'receipt'=>'<path d="M6 3h12v18l-3-2-3 2-3-2-3 2ZM9 7h6M9 11h6M9 15h3"/>',
        'return'=>'<path d="M8 4 3 9l5 5M3 9h11a6 6 0 0 1 0 12h-3"/>',
        'sale'=>'<path d="M3 4h2l3 12h11l3-8H6M9 20h.01M18 20h.01"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5"/>',
        'wallet'=>'<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 8V5l14-3v3M16 11h5v5h-5Z"/>',
        'chart'=>'<path d="M3 3v18h18M7 16l4-5 4 2 5-8"/>',
        'settings'=>'<path d="m9 3-1 3-3 1-2 4 2 2 1 4 3 1 3 3 3-3 3-1 1-4 2-2-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        'chevron-down'=>'<path d="m6 9 6 6 6-6"/>',
        'search'=>'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'logout'=>'<path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M7 14h2M13 14h2"/>',
        'edit'=>'<path d="m15 4 5 5M4 20l5-1L21 7l-5-5L4 14ZM4 20h16"/>',
        'archive'=>'<path d="M3 3h18v5H3ZM5 8v13h14V8M10 12h4"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
    ];
    return '<svg class="icon '.e($class).'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['box']).'</svg>';
}
function role_label(array $user): string { return ['superadmin'=>'Superadmin','shop_owner'=>'Shop owner','staff'=>'Staff'][$user['role']]; }
function money(mixed $value): string { return '₹'.number_format((float)$value,2); }
function quantity(mixed $value): string { return rtrim(rtrim(number_format((float)$value,6,'.',','),'0'),'.'); }
function local_datetime(string $value): string { return (new DateTimeImmutable($value,new DateTimeZone('Asia/Kolkata')))->format('d M, g:i a'); }
function category_view_state(array $categories,array $filters): array {
    $query=mb_strtolower(trim($filters['q']));$status=$filters['status'];$level=$filters['view'];
    $visible=[];$children=[];$expanded=[];
    foreach ($categories as $category) {
        $match=$query==='' || str_contains(mb_strtolower($category['name'].' '.$category['slug'].' '.$category['description']),$query);
        $main=$match && ($status==='all' || ($status==='archived'?!empty($category['archived_at']):($status==='active'?(empty($category['archived_at']) && $category['status']==='active'):$category['status']===$status))) && $level!=='subcategory';
        foreach ($category['subcategories'] as $sub) {
            $subMatch=$match || $query==='' || str_contains(mb_strtolower($sub['name'].' '.$sub['slug'].' '.$sub['description']),$query);
            if ($level!=='category' && $subMatch && ($status==='all' || ($status==='archived'?!empty($sub['archived_at']):($status==='active'?(empty($sub['archived_at']) && $sub['status']==='active'):$sub['status']===$status)))) $children[]=(int)$sub['id'];
        }
        $hasChildren=(bool)array_intersect(array_column($category['subcategories'],'id'),$children);
        if ($main || $hasChildren) {
            $visible[]=(int)$category['id'];
            if ($hasChildren && ($query!=='' || $status!=='all' || $level==='subcategory')) $expanded[]=(int)$category['id'];
        }
    }
    return ['categories'=>$visible,'subcategories'=>$children,'expanded'=>$expanded];
}
