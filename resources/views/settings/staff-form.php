<?php $titles=['create'=>'Add staff','edit'=>'Edit staff details','reset'=>'Reset password','status'=>'Change staff status']; ?>
<section class="page-heading"><div><h1><?= e($titles[$action]) ?></h1><p>Staff accounts belong to your shop.</p></div><a class="button" href="<?= e(url('/staff')) ?>">Back to staff</a></section>
<form method="post" action="<?= e(url('/staff/save')) ?>" class="surface staff-account-page"><?php $inDialog=false;require __DIR__.'/staff-fields.php'; ?></form>
