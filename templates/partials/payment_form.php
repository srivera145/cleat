<?php
/**
 * The card form, shared by every page that takes a card.
 *
 * Card inputs carry no name attribute, so the card number, expiry and code
 * can never be posted to this server. Accept.js turns them into a one-time
 * token in the browser; only dataDescriptor and dataValue are submitted.
 * Authentication uses the public apiLoginID and client key, never the
 * transaction key.
 *
 * @var callable $e
 * @var callable $json
 * @var callable $partial
 * @var callable $raw
 * @var string $csrf
 * @var string $submitLabel
 * @var string $acceptJsUrl
 * @var string $apiLoginId
 * @var string $clientKey
 * @var string $cspNonce
 * @var bool $fakeMode
 * @var array<string, string> $testTokens
 * @var string $captchaHtml
 * @var list<string> $errors
 * @var string|null $fieldsPartial  page-specific fields rendered first
 * @var array $fieldsVars
 * @var string|null $savedCard      "Visa ending 4242" to offer the saved card
 * @var bool $allowSave             show "save this card"
 */
$fieldsPartial ??= null;
$fieldsVars ??= [];
$savedCard ??= null;
$allowSave ??= false;
?>
<form method="post" id="cleat-pay-form" class="stack stack-4" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="dataDescriptor" id="cleat-data-descriptor" value="">
  <input type="hidden" name="dataValue" id="cleat-data-value" value="">

  <?= $partial('errors', ['errors' => $errors]) ?>

<?php if ($fieldsPartial !== null): ?>
  <?= $partial($fieldsPartial, $fieldsVars) ?>
<?php endif; ?>

<?php if ($savedCard !== null): ?>
  <fieldset class="stack stack-2 cleat-choices">
    <legend class="label mb-2">Payment method</legend>
    <label class="check check-card">
      <input type="radio" name="payment_method" value="saved" checked>
      <span class="check-text">
        <span class="fw-semi">Pay with <?= $e($savedCard) ?></span>
        <span class="check-note">The card we have on file</span>
      </span>
    </label>
    <label class="check check-card">
      <input type="radio" name="payment_method" value="new">
      <span class="check-text">
        <span class="fw-semi">Use a different card</span>
      </span>
    </label>
  </fieldset>
<?php else: ?>
  <input type="hidden" name="payment_method" value="new">
<?php endif; ?>

  <div id="cleat-card-fields" class="stack stack-3"<?php if ($savedCard !== null): ?> hidden<?php endif; ?>>
<?php if ($fakeMode): ?>
    <div class="alert alert-info">
      <div>
        <p class="alert-title">Test mode</p>
        <p class="alert-body">No real card is charged. Choose a test card to see each outcome.</p>
      </div>
    </div>
    <div class="field">
      <label class="label" for="cleat-test-token">Test card</label>
      <select class="select" id="cleat-test-token">
<?php foreach ($testTokens as $token => $label): ?>
        <option value="<?= $e($token) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
<?php else: ?>
    <div class="field">
      <label class="label" for="cleat-card-number">Card number</label>
      <input class="input cleat-card-input" id="cleat-card-number" type="text" inputmode="numeric" autocomplete="cc-number" placeholder="1234 1234 1234 1234" maxlength="23" data-cleat-card>
    </div>
    <div class="grid grid-fixed-2 gap-3">
      <div class="field">
        <label class="label" for="cleat-card-exp">Expiry</label>
        <input class="input cleat-card-input" id="cleat-card-exp" type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM / YY" maxlength="7" data-cleat-card>
      </div>
      <div class="field">
        <label class="label" for="cleat-card-cvc">Security code</label>
        <input class="input cleat-card-input" id="cleat-card-cvc" type="text" inputmode="numeric" autocomplete="cc-csc" placeholder="CVC" maxlength="4" data-cleat-card>
      </div>
    </div>
    <div class="field">
      <label class="label" for="cleat-card-zip">ZIP or postal code <span class="optional">Optional</span></label>
      <input class="input" id="cleat-card-zip" type="text" autocomplete="postal-code" maxlength="20" data-cleat-card>
    </div>
<?php endif; ?>
<?php if ($allowSave): ?>
    <label class="check">
      <input type="checkbox" name="save_card" value="1">
      <span class="check-text">
        <span>Save this card for future payments</span>
        <span class="check-note">Stored securely by Authorize.net</span>
      </span>
    </label>
<?php endif; ?>
  </div>

  <div id="cleat-card-errors" class="alert alert-bad" aria-live="polite" hidden>
    <div><p class="alert-body" id="cleat-card-errors-text"></p></div>
  </div>

  <?= $raw($captchaHtml) ?>

  <button type="submit" class="btn btn-primary btn-lg btn-block" data-cleat-submit>
    <span data-cleat-idle><?= $e($submitLabel) ?></span>
    <span class="cleat-busy-label" data-cleat-busy hidden><span class="spinner" aria-hidden="true"></span> Processing…</span>
  </button>
</form>
<?php if (!$fakeMode): ?>
<script src="<?= $e($acceptJsUrl) ?>" charset="utf-8" nonce="<?= $e($cspNonce) ?>"></script>
<?php endif; ?>
<script nonce="<?= $e($cspNonce) ?>">
(function () {
  'use strict';
  var config = <?= $json(['apiLoginID' => $apiLoginId, 'clientKey' => $clientKey, 'fake' => (bool) $fakeMode]) ?>;
  var form = document.getElementById('cleat-pay-form');
  if (!form) { return; }
  var descriptor = document.getElementById('cleat-data-descriptor');
  var value = document.getElementById('cleat-data-value');
  var cardFields = document.getElementById('cleat-card-fields');
  var errorBox = document.getElementById('cleat-card-errors');
  var errorText = document.getElementById('cleat-card-errors-text');
  var button = form.querySelector('[data-cleat-submit]');
  var $ = function (id) { return document.getElementById(id); };

  function usingSavedCard() {
    var choice = form.querySelector('input[name="payment_method"]:checked');
    return !!choice && choice.value === 'saved';
  }
  function showErrors(messages) {
    errorText.textContent = messages.join(' ');
    errorBox.hidden = false;
  }
  function busy(on) {
    button.disabled = on;
    button.setAttribute('aria-busy', on ? 'true' : 'false');
    button.querySelector('[data-cleat-idle]').hidden = on;
    button.querySelector('[data-cleat-busy]').hidden = !on;
  }
  function submitToken(dataDescriptor, dataValue) {
    descriptor.value = dataDescriptor;
    value.value = dataValue;
    form.querySelectorAll('[data-cleat-card]').forEach(function (input) { input.value = ''; });
    form.dataset.tokenized = '1';
    form.submit();
  }

  form.addEventListener('change', function (event) {
    if (event.target.name === 'payment_method') { cardFields.hidden = usingSavedCard(); }
  });

  var number = $('cleat-card-number');
  var exp = $('cleat-card-exp');
  if (number) {
    number.addEventListener('input', function () {
      var digits = number.value.replace(/\D/g, '').slice(0, 19);
      number.value = digits.replace(/(\d{4})(?=\d)/g, '$1 ');
    });
  }
  if (exp) {
    exp.addEventListener('input', function () {
      var digits = exp.value.replace(/\D/g, '').slice(0, 4);
      exp.value = digits.length > 2 ? digits.slice(0, 2) + ' / ' + digits.slice(2) : digits;
    });
  }

  form.addEventListener('submit', function (event) {
    errorBox.hidden = true;
    if (form.dataset.tokenized === '1' || usingSavedCard()) { busy(true); return; }
    event.preventDefault();

    if (config.fake) {
      busy(true);
      submitToken('COMMON.ACCEPT.INAPP.PAYMENT', $('cleat-test-token').value);
      return;
    }

    var cardNumber = number.value.replace(/\D/g, '');
    var expiry = exp.value.replace(/\D/g, '');
    var month = expiry.slice(0, 2);
    var year = expiry.slice(2);
    var cardCode = $('cleat-card-cvc').value.replace(/\D/g, '');
    var problems = [];
    if (cardNumber.length < 12) { problems.push('Enter your full card number.'); }
    if (expiry.length !== 4 || +month < 1 || +month > 12) { problems.push('Enter the expiry date as MM / YY.'); }
    if (cardCode.length < 3) { problems.push('Enter the security code from your card.'); }
    if (problems.length) { showErrors(problems); return; }
    if (typeof window.Accept === 'undefined') {
      showErrors(['The secure payment form could not load. Check your connection and reload the page.']);
      return;
    }

    busy(true);
    var cardData = { cardNumber: cardNumber, month: month, year: '20' + year, cardCode: cardCode };
    var zip = $('cleat-card-zip').value.trim();
    if (zip) { cardData.zip = zip; }
    window.Accept.dispatchData({ authData: { clientKey: config.clientKey, apiLoginID: config.apiLoginID }, cardData: cardData }, function (response) {
      if (!response || !response.messages || response.messages.resultCode === 'Error') {
        var list = (response && response.messages && response.messages.message) || [];
        showErrors(list.length ? list.map(function (m) { return m.text; }) : ['Your card could not be verified. Check the details and try again.']);
        busy(false);
        return;
      }
      submitToken(response.opaqueData.dataDescriptor, response.opaqueData.dataValue);
    });
  });
})();
</script>
