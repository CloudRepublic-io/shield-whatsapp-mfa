<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.enrollHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.enrollIntro') ?></p>

<form method="post" action="<?= url_to('auth-action-handle') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="phone" class="form-label"><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.phoneLabel') ?></label>
        <input
            type="tel"
            id="phone"
            name="phone"
            class="form-control"
            placeholder="<?= lang('WhatsAppMfa.phonePlaceholder') ?>"
            value="<?= esc(old('phone')) ?>"
            required
        >
    </div>

    <button type="submit" class="btn btn-primary">
        <?= lang('WhatsAppMfa.sendCodeButton') ?>
    </button>
</form>

<?php if (\Config\Services::routes()->reverseRoute('whatsapp-activator-skip') !== false) : ?>
<form method="post" action="<?= url_to('whatsapp-activator-skip') ?>" class="mt-2">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-link p-0">
        <?= lang('WhatsAppMfa.skipButton') ?>
    </button>
</form>
<?php endif ?>

<?= $this->endSection() ?>
