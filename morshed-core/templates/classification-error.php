<?php if($retryPath==='/taxonomy/delete'): ?><h1>حذف انجام نشد</h1><p class="errorbox"><?= $this->h($message) ?></p><a class="secondary" href="<?= $this->h($this->url('/taxonomy')) ?>">بازگشت به مدیریت موضوعات و مسائل</a><?php else: ?>
<h1>اطلاعات نیاز به اصلاح دارد</h1><p class="errorbox"><?= $this->h($message) ?></p><p>اطلاعات واردشده در زیر حفظ شده است. پس از اصلاح، دوباره ارسال کنید.</p>
<?= $this->start($retryPath) ?><?php foreach($input as $name=>$value):if(!is_string($value)||in_array($name,['csrf','current_password'],true)){continue;} ?><?= $this->field($name,$name,'text',$value) ?><?php endforeach; ?><button>ارسال دوباره</button></form>

<?php endif; ?>