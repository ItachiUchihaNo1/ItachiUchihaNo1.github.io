<?php $isAudience=$kind==='audiences';$catalogTitle=$isAudience?'مدیریت گروه‌های مخاطب':'مدیریت انواع مجموعه'; ?>
<h1><?= $catalogTitle ?></h1><p class="muted">شناسه‌ها با تغییر نام ثابت می‌مانند. غیرفعال‌کردن، سوابق تجربه‌ها را حذف نمی‌کند.</p>
<form method="get" class="search section"><input type="hidden" name="r" value="/<?= $kind ?>"><label class="sr-only" for="catalog-query">جست‌وجوی عنوان</label><input id="catalog-query" name="q" value="<?= $this->h($_GET['q']??'') ?>" placeholder="جست‌وجوی عنوان"><button>جست‌وجو</button></form>
<?php $catalogRows=[['id'=>'','title'=>'','description'=>'','active'=>1,'family'=>'age','display_order'=>0],...$rows];foreach($catalogRows as $entry): ?>
<details class="card section" <?= !$entry['id']?'open':'' ?>><summary><?= $this->h($entry['title']?:'افزودن مورد جدید') ?><?php if($entry['id']): ?> · <?= $this->number($entry['usage_count']) ?> کاربرد · <?= $entry['active']?'فعال':'غیرفعال' ?><?php endif; ?></summary>
<?= $this->start('/classification/catalog/save') ?><input type="hidden" name="id" value="<?= $this->h($entry['id']) ?>"><input type="hidden" name="kind" value="<?= $kind ?>">
<?= $this->field('title','عنوان','text',$entry['title'],true,160) ?>
<?php if($isAudience): ?><label>خانوادهٔ مخاطب<select name="family"><?php foreach(\Morshed\Product\Classifications::FAMILIES as $key=>$name): ?><option value="<?= $key ?>" <?= $entry['family']===$key?'selected':'' ?>><?= $name ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>توضیح<textarea name="description" maxlength="6000" rows="3"><?= $this->h($entry['description']) ?></textarea></label><?= $this->field('display_order','ترتیب نمایش','number',(string)$entry['display_order']) ?>
<label>وضعیت<select name="active"><option value="1" <?= $entry['active']?'selected':'' ?>>فعال</option><option value="0" <?= !$entry['active']?'selected':'' ?>>غیرفعال</option></select></label><button>ذخیره</button></form></details><?php endforeach; ?>
