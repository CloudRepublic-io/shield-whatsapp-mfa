<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('WhatsAppMfa.settingsHeading') ?></h1>

<?php if (session('message')) : ?>
    <div class="alert alert-success"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p class="text-muted"><?= lang('WhatsAppMfa.settingsIntro') ?></p>

<?php if ($verifiedPhone === null) : ?>
    <p><?= lang('WhatsAppMfa.noPhoneSet') ?></p>
    <a href="<?= url_to('whatsapp-settings-enroll') ?>" class="btn btn-primary">
        <?= lang('WhatsAppMfa.addButton') ?>
    </a>
<?php else : ?>
    <p>
        <strong><?= lang('WhatsAppMfa.currentPhoneLabel') ?>:</strong>
        <?= esc($verifiedPhone) ?>
    </p>

    <a href="<?= url_to('whatsapp-settings-enroll') ?>" class="btn btn-outline-secondary">
        <?= lang('WhatsAppMfa.changeButton') ?>
    </a>

    <form method="post" action="<?= url_to('whatsapp-settings-disable') ?>" class="d-inline" onsubmit="return confirm('<?= lang('WhatsAppMfa.removeConfirm') ?>');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-danger">
            <?= lang('WhatsAppMfa.removeButton') ?>
        </button>
    </form>
<?php endif ?>

<?= $this->endSection() ?>
