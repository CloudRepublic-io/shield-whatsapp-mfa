<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.settingsHeading') ?></h1>

<?php if (session('message')) : ?>
    <div class="alert alert-success"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p class="text-muted"><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.settingsIntro') ?></p>

<?php if ($verifiedPhone === null) : ?>
    <p><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.noPhoneSet') ?></p>
    <a href="<?= url_to('whatsapp-settings-enroll') ?>" class="btn btn-primary">
        <?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.addButton') ?>
    </a>
<?php else : ?>
    <p>
        <strong><?= lang('WhatsAppMfa.currentPhoneLabel') ?>:</strong>
        <?= esc($verifiedPhone) ?>
    </p>

    <a href="<?= url_to('whatsapp-settings-enroll') ?>" class="btn btn-outline-secondary">
        <?= lang('WhatsAppMfa.changeButton') ?>
    </a>

    <form method="post" action="<?= url_to('whatsapp-settings-disable') ?>" class="d-inline" onsubmit="return confirm('<?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.removeConfirm') ?>');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-danger">
            <?= lang('WhatsAppMfa.removeButton') ?>
        </button>
    </form>

    <?php if ($testIsRelevant) : ?>
        <hr class="my-3">
        <p class="text-muted small">
            <?= $whatsAppConfirmed ? lang('WhatsAppMfa.testStatusConfirmed') : lang('WhatsAppMfa.testStatusNotConfirmed') ?>
        </p>
        <?php if ($whatsAppConfirmed) : ?>
            <p><?= lang('WhatsAppMfa.testAlreadyConfirmed') ?></p>
        <?php else : ?>
            <a href="<?= url_to('whatsapp-settings-test-enroll') ?>" class="btn btn-outline-primary btn-sm">
                <?= lang('WhatsAppMfa.testButton') ?>
            </a>
        <?php endif ?>
    <?php endif ?>
<?php endif ?>

<?= $this->endSection() ?>
