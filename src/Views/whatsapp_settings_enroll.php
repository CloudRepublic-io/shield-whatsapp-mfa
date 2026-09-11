<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.enrollHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= \WhatsAppMfa\Libraries\ChannelLabel::inject('WhatsAppMfa.enrollIntro') ?></p>

<form method="post" action="<?= url_to('whatsapp-settings-send') ?>">
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

<a href="<?= url_to('whatsapp-settings') ?>" class="btn btn-link">Cancel</a>

<?= $this->endSection() ?>
