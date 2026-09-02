<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('WhatsAppMfa.enrollHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p>
    <?= str_replace('{phone}', esc($phone_masked), lang('WhatsAppMfa.confirmVerifyIntro')) ?>
</p>

<form method="post" action="<?= url_to('auth-action-verify') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="code" class="form-label"><?= lang('WhatsAppMfa.codeLabel') ?></label>
        <input
            type="text"
            id="code"
            name="code"
            class="form-control"
            inputmode="numeric"
            autocomplete="one-time-code"
            required
        >
    </div>

    <button type="submit" class="btn btn-primary">
        <?= lang('WhatsAppMfa.verifyButton') ?>
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
