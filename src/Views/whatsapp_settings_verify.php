<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('WhatsAppMfa.enrollHeading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p>
    <?= str_replace('{phone}', esc($phone_masked), lang('WhatsAppMfa.confirmVerifyIntro')) ?>
</p>

<form method="post" action="<?= url_to('whatsapp-settings-confirm') ?>">
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

<a href="<?= url_to('whatsapp-settings-enroll') ?>" class="btn btn-link">
    <?= lang('WhatsAppMfa.changeButton') ?>
</a>

<?= $this->endSection() ?>
