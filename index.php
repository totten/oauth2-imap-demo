<?php
require_once 'vendor/autoload.php';
require_once 'vendor/civicrm/civicrm-core/CRM/Core/ClassLoader.php';
require_once 'vendor/civicrm/civicrm-core/ext/oauth-client/CRM/OAuth/MailSetup.php';
require_once 'vendor/civicrm/civicrm-core/ext/oauth-client/Civi/OAuth/CiviGenericProvider.php';
CRM_Core_ClassLoader::singleton()->register();

/**
 * Read the file 'provider.json'. Merge in defaults.
 *
 * @return array
 *   - class: string, the PHP class of the OAuth2 provider
 *   - options: array, list of constructor options
 *   - mailSettingsTemplate: array
 */
function createOAuthProviderDefn() {
  $cfg = json_decode(file_get_contents(__DIR__ . '/provider.json'), 1);

  $defaults = [
    'class' => \Civi\OAuth\CiviGenericProvider::class,
    'options' => [
      'redirectUri' => 'http://' . $_SERVER['HTTP_HOST'],
    ],
  ];

  $defn = json_decode(file_get_contents(__DIR__ . '/' . $cfg['file']), 1);
  $defn['options'] = array_merge($defaults['options'], $defn['options'], $cfg['options']);
  $defn = array_merge($defaults, $defn);

  return $defn;
}

function createImap($host, $username, $password, $ssl = TRUE) {
  $options = [
    'listLimit' => defined('MAIL_BATCH_SIZE') ? MAIL_BATCH_SIZE : 1000,
    'ssl' => $ssl,
    'uidReferencing' => TRUE,
  ];
  $transport = new ezcMailImapTransport($host, NULL, $options);
  $transport->authenticate($username, $password, ezcMailImapTransport::AUTH_XOAUTH2);
  return $transport;
}

function dump($data) {
  printf("<pre>\n%s</pre>\n", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function buildInputUrl($s) {
  $ssl = !empty($s['HTTPS']) && strtolower($s['HTTPS']) != 'off';
  $url = ($ssl ? 'https' : 'http') . '://' . $s['HTTP_HOST'] . $s['REQUEST_URI'];
  return $url;
}

// -------------------------------------------------------------------------
// Main

error_log($_SERVER['REQUEST_METHOD'] . ' ' . buildInputUrl($_SERVER));
$providerDefn = createOAuthProviderDefn();
$class = $providerDefn['class'];
$provider = new $class($providerDefn['options']);

// If we don't have an authorization code then get one
if (!isset($_GET['code'])) {

  // Fetch the authorization URL from the provider; this returns the
  // urlAuthorize option and generates and applies any necessary parameters
  // (e.g. state).
  $authorizationUrl = $provider->getAuthorizationUrl();

  // Get the state generated for you and store it to the session.
  $_SESSION['oauth2state'] = $provider->getState();

  // Redirect the user to the authorization URL.
  printf("<a href='%s'>Request email access via OAuth2</a><br/>\n", htmlentities($authorizationUrl));
  printf("(URL: %s)\n", htmlentities($authorizationUrl));
  dump(['providerDefn' => $providerDefn]);
  exit();

  // Check given state against previously stored one to mitigate CSRF attack
}
elseif (empty($_GET['state']) || (isset($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state'])) {
  printf('<a href="%s">Start over</a><br/>', '/');

  if (isset($_SESSION['oauth2state'])) {
    unset($_SESSION['oauth2state']);
  }
  exit('Invalid state');

}
else {

  printf('<a href="%s">Start over</a><br/>', '/');

  dump(['providerDefn' => $providerDefn]);

  // Get the actual token and information about th euser.
  $accessToken = $provider->getAccessToken('authorization_code', [
    'code' => $_GET['code'],
  ]);
  try {
    $resourceOwner = $provider->getResourceOwner($accessToken)->toArray();
  }
  catch (Exception $e) {
    printf("<pre>\nERROR:\n%s\n</pre>", htmlentities($e->getTraceAsString()));
  }

  $tokenRecord = [
    'access_token' => $accessToken->getToken(),
    'refresh_token' => $accessToken->getRefreshToken(),
    'expires' => $accessToken->getExpires(),
    'raw' => $accessToken->jsonSerialize(),
    'resource_owner_name' => $resourceOwner['mail'] ?? $resourceOwner['email'] ?? $resourceOwner['upn'] ?? NULL,
    'resource_owner' => $resourceOwner,
  ];
  dump(['tokenRecord' => $tokenRecord]);

  $mailSettings = CRM_OAuth_MailSetup::evalArrayTemplate($providerDefn['mailSettingsTemplate'], ['token' => $tokenRecord]);
  dump(['mailSettings' => $mailSettings]);

  $imap = createImap($mailSettings['server'], $mailSettings['username'], $tokenRecord['access_token'], $mailSettings['is_ssl']);
  $imap->selectMailbox('INBOX');
  $boxes = $imap->listMailboxes();
  printf("<p>Found %d mail boxes:</p>\n", count($boxes));
  printf("<ul>\n");
  foreach ($boxes as $box) {
    printf("<li>%s</li>\n", htmlentities($box));
  }
  printf("</ul>\n");
}
