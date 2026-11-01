<?php if(!empty($attachment)): $fileUrl=$this->url('/chat/file?id='.$attachment['id']);$fileMime=$attachment['mime']; ?>
<div class="chat-attachment">
<?php if(str_starts_with($fileMime,'image/')): ?><a href="<?= $this->h($fileUrl) ?>" target="_blank" rel="noopener"><img src="<?= $this->h($fileUrl) ?>" loading="lazy" alt="<?= $this->h($attachment['name']) ?>"></a>
<?php elseif(str_starts_with($fileMime,'video/')): ?><video controls preload="none" playsinline src="<?= $this->h($fileUrl) ?>" aria-label="<?= $this->h($attachment['name']) ?>"></video>
<?php elseif(str_starts_with($fileMime,'audio/')): ?><audio controls preload="none" src="<?= $this->h($fileUrl) ?>" aria-label="<?= $this->h($attachment['name']) ?>"></audio><?php endif; ?>
<a class="chat-file-link" href="<?= $this->h($fileUrl.'&download=1') ?>" download><?= $this->icon('book') ?><span><?= $this->h($attachment['name']) ?><small><?= $this->number((int)ceil($attachment['size']/1024)) ?> کیلوبایت · دریافت فایل</small></span></a>
</div><?php endif; ?>
