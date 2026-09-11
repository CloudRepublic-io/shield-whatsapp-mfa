<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('WhatsAppMfa.stepUpHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p>
    <?= str_replace('{phone}', esc($phone_masked), \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.stepUpIntro')) ?>
</p>

<form method="post" action="<?= url_to('whatsapp-step-up-send') ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary">
        <?= lang('WhatsAppMfa.sendCodeButton') ?>
    </button>
</form>

<?= $this->endSection() ?>
