<?php
$system=($this->actor['admin_role']??'')==='system_admin';
$groups=[
 'محتوا و ویترین'=>['/experiences'=>'تجربه‌ها','/taxonomy?kind=topic'=>'موضوعات','/taxonomy?kind=issue'=>'برچسب‌های مسئله','/organization-types'=>'انواع مجموعه','/audiences'=>'گروه‌های مخاطب','/storefront'=>'ویترین'],
 'حساب کاربری مدیریت'=>['/security'=>'امنیت حساب من'],
 'آرشیو مجاز'=>['/archive'=>'مشاهده با مجوز موردی']
];
if($system){
 $groups['محتوا و ویترین']+=['/mentors'=>'مرشدها','/atlas/manage'=>'مجموعه‌ها و اطلس','/knowledge'=>'روابط و فراداده'];
 $groups['کاربران و جلسات']=['/users'=>'کاربران','/bookings'=>'جلسات','/experience-leads'=>'پیشنهادهای تجربه','/support'=>'درخواست‌های پشتیبانی','/archives'=>'مجوزهای آرشیو','/organizations'=>'عضویت عملیاتی'];
 $groups['حساب کاربری مدیریت']+=['/admins'=>'دسترسی مدیران','/settings'=>'وضعیت اتصال‌ها'];
 $groups['تنظیمات سامانه']=['/general'=>'تنظیمات عمومی','/legal'=>'قوانین پایه','/notification-templates'=>'قالب اعلان','/notification-deliveries'=>'سوابق اعلان'];
 $groups['عضویت و مشاوره']=['/membership/policy'=>'سیاست‌های عضویت و اعتبار','/membership/reviews'=>'احراز و عضویت مجموعه‌ها','/membership/networks'=>'شبکه‌ها و تفویض اختیار','/membership/credits'=>'اعطای اعتبار','/membership/behavior'=>'رسیدگی رفتاری','/membership/reports'=>'گزارش خدمات'];
 $groups['گزارش و نظارت']=['/reports'=>'گزارش‌ها','/audit'=>'حسابرسی'];
}
$route=is_string($_GET['r']??null)?$_GET['r']:(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');$route=$route==='/index.php'?'/':$route;
?>
<a class="panel-nav-link" <?= $route==='/'?'aria-current="page"':'' ?> href="<?= $this->h($this->url('/')) ?>"><?= $this->icon('home') ?><span>داشبورد</span></a>
<?php if($system): ?><a class="panel-nav-link" <?= in_array($route,['/messages','/eitaa-delivery'],true)?'aria-current="page"':'' ?> href="<?= $this->h($this->url('/messages')) ?>"><?= $this->icon('chat') ?><span>مرکز پیام‌ها</span></a><?php endif; ?>
<?php foreach($groups as $groupTitle=>$links):$groupOpen=false;foreach($links as $target=>$label){[$base,$query]=array_pad(explode('?',$target,2),2,'');if($route===$base&&(!$query||str_contains($query,'kind='.($_GET['kind']??'topic')))){$groupOpen=true;}} ?>
<details class="admin-nav-group" <?= $groupOpen?'open':'' ?>><summary><?= $this->h($groupTitle) ?></summary><div><?php foreach($links as $target=>$label):[$base,$query]=array_pad(explode('?',$target,2),2,'');$active=$route===$base&&(!$query||str_contains($query,'kind='.($_GET['kind']??'topic'))); ?><a class="panel-nav-link" <?= $active?'aria-current="page"':'' ?> href="<?= $this->h($this->url($target)) ?>"><?= $this->icon(match($base){'/security','/admins'=>'security','/users','/mentors'=>'users','/bookings'=>'calendar','/experiences','/archive'=>'book','/support'=>'chat',default=>'topic'}) ?><span><?= $this->h($label) ?></span></a><?php endforeach; ?></div></details>
<?php endforeach; ?>
