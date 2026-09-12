<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('WhatsAppMfa.testHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= lang('WhatsAppMfa.testIntro') ?></p>

<form method="post" action="<?= url_to('whatsapp-settings-test-send') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="phone" class="form-label">WhatsApp number</label>
        <input
            type="tel"
            id="phone"
            name="phone"
            class="form-control"
            placeholder="<?= lang('WhatsAppMfa.phonePlaceholder') ?>"
            value="<?= esc(old('phone') ?? $verifiedPhone ?? '') ?>"
            required
        >
    </div>

    <button type="submit" class="btn btn-primary">
        <?= lang('WhatsAppMfa.testSendCodeButton') ?>
    </button>
</form>

<a href="<?= url_to('whatsapp-settings') ?>" class="btn btn-link">Cancel</a>

<?= $this->endSection() ?>
