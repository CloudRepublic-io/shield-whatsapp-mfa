<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('WhatsAppMfa.heading') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p>
    <?= str_replace('{phone}', esc($phone_masked), lang('WhatsAppMfa.verifyIntro')) ?>
</p>

<form method="post" action="<?= url_to('auth-action-verify') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="code" class="form-label"><?= lang('WhatsAppMfa.codeLabel') ?></label>
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            autocomplete="one-time-code"
            id="code"
            name="code"
            class="form-control"
            maxlength="6"
            autofocus
            required
        >
    </div>

    <button type="submit" class="btn btn-primary">
        <?= lang('WhatsAppMfa.verifyButton') ?>
    </button>
</form>

<form method="post" action="<?= url_to('auth-action-handle') ?>" id="resend-form" class="mt-3">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-link p-0" id="resend-button" disabled>
        <?= lang('WhatsAppMfa.resendButton') ?>
    </button>
    <span id="resend-timer">
        <?= str_replace('{seconds}', (string) $resend_seconds, lang('WhatsAppMfa.resendWait')) ?>
    </span>
</form>

<script>
    (function () {
        var seconds = <?= (int) $resend_seconds ?>;
        var button  = document.getElementById('resend-button');
        var timer   = document.getElementById('resend-timer');

        var interval = setInterval(function () {
            seconds -= 1;

            if (seconds <= 0) {
                clearInterval(interval);
                button.disabled = false;
                timer.style.display = 'none';
                return;
            }

            timer.textContent = seconds + 's before you can resend';
        }, 1000);
    })();
</script>

<?= $this->endSection() ?>
