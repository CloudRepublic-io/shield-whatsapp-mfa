<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('WhatsAppMfa.enrollHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= lang('WhatsAppMfa.enrollIntro') ?></p>

<form method="post" action="<?= url_to('auth-action-handle') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="phone" class="form-label"><?= lang('WhatsAppMfa.phoneLabel') ?></label>
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

<form method="post" action="<?= url_to('whatsapp-activator-skip') ?>" class="mt-2">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-link p-0">
        <?= lang('WhatsAppMfa.skipButton') ?>
    </button>
</form>

<?= $this->endSection() ?>
