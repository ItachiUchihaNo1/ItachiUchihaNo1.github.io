<?php $footerSettings=$footerSettings??[];$footerText=trim($footerSettings['footer_text']??'')?:'مرشد، جایی برای پیدا کردن صاحب تجربه و گفت‌وگوی مستقیم دربارهٔ مسیر پیش روی توست.'; ?>
<footer class="home-footer" aria-label="درباره مرشد و اطلاعات تماس">
<div class="footer-about"><h2>یک تجربه، آغاز یک گفت‌وگو</h2><p><?= $this->h($footerText) ?></p><a class="textlink" href="<?= $this->h($this->url('/about')) ?>">بیشتر دربارهٔ مرشد</a></div>
<div class="footer-contact"><h2>ارتباط با مرشد</h2><address>
<?php if(!empty($footerSettings['support_phone'])): ?><div><span>شماره تماس</span><bdi><?= $this->h($footerSettings['support_phone']) ?></bdi></div><?php endif; ?>
<?php if(!empty($footerSettings['support_email'])): ?><div><span>ایمیل</span><a href="mailto:<?= $this->h($footerSettings['support_email']) ?>"><bdi><?= $this->h($footerSettings['support_email']) ?></bdi></a></div><?php endif; ?>
<?php if(!empty($footerSettings['support_address'])): ?><div><span>آدرس</span><p><?= nl2br($this->h($footerSettings['support_address'])) ?></p></div><?php endif; ?>
</address><a class="textlink" href="<?= $this->h($this->url('/support')) ?>">تماس با پشتیبانی</a></div>
<div class="footer-trust"><?php if(!empty($footerSettings['enamad_asset'])): ?><?php if(!empty($footerSettings['enamad_link'])): ?><a href="<?= $this->h($footerSettings['enamad_link']) ?>" rel="noopener noreferrer"><?php endif; ?><img class="footer-enamad" src="<?= $this->h($this->url('/content/asset?id='.$footerSettings['enamad_asset'])) ?>" width="110" height="126" alt="نماد اعتماد الکترونیکی" loading="lazy"><?php if(!empty($footerSettings['enamad_link'])): ?></a><?php endif; ?><?php else: ?><div class="enamad-slot" aria-label="جایگاه نماد اعتماد الکترونیکی؛ نمادی ثبت نشده است"><span aria-hidden="true">◇</span><span>جایگاه اینماد</span></div><?php endif; ?></div>
<a class="footer-brand" href="<?= $this->h(($footerSettings['footer_logo_link']??'')?:$this->url('/')) ?>" aria-label="مرشد، صفحهٔ اصلی"><img src="<?= $this->h(!empty($footerSettings['footer_logo_asset'])?$this->url('/content/asset?id='.$footerSettings['footer_logo_asset']):'/assets/morshed-logo.png') ?>" width="112" height="112" loading="lazy" alt="لوگوی مرشد"><strong>مرشد</strong><span>سامانهٔ گفت‌وگو با تجربه‌ها</span></a>
<p class="footer-copyright">مرشد؛ همراه تو از تجربه تا گفت‌وگو</p>
</footer>
