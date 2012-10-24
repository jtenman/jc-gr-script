<?php

define('GOODREADS_BASE_URL', 'http://www.goodreads.com');
include('simple_html_dom.php');
include('gr_creds.php');

/**
 * Log in to goodreads.
 */
function goodreads_login($u, $p) {
  global $gr_debug;
  static $run = FALSE;
  if ($run) {
    return;
  }
  $run = TRUE;

  $headers = $values = array();
  $url = 'https://www.goodreads.com/user/sign_in';
  $output = _goodreads_curl($url);

  // User is already logged in.
  if (_goodreads_is_logged_in($output)) {
    return TRUE;
  }

  // Search for login form.
  $html = str_get_html($output);
  $form = $html->find('form[name=sign_in]');
  $form = array_pop($form);
  if (!$form) {
    die('Could not find the sign_in form.');
  }
  // Add in the hidden values.
  foreach ($form->find('input[type=hidden]') as $input) {
    $values[$input->name] = $input->value;
  }
  $values['user[email]'] = $u;
  $values['user[password]'] = $p;
  $headers[CURLOPT_POST] = TRUE;
  $headers[CURLOPT_POSTFIELDS] = http_build_query($values);
  $output = _goodreads_curl($url, $headers);
  _goodreads_debug('login.html', $output);
  if (!_goodreads_is_logged_in($output)) {
    die ('Failed to login (could not find profile link)');
  }
  echo "Logged In\n";
  //echo $output;
  return TRUE;
}

/**
 * Check if logged in.
 */
function _goodreads_is_logged_in($output) {
  $html = str_get_html($output);
  $logged = $html->find('body div[class="content"] div[id="siteheader"] div[class="mainContent"] ul[id="usernav"] a[class="profileSubNavLink"]');
  if (!$output || empty($logged)) {
    return FALSE;

  }
  return TRUE;
}

/**
 * Fake logout. Just remove the cookies.
 */
function _goodreads_logout() {
  unlink(getcwd() . '/gr.tmp');
}

function _goodreads_curl($url, $default_headers = array(), $close = FALSE) {
  $headers = array();
  $type = substr($url, 0, 5);
  if ($type == 'https') {
    $headers[CURLOPT_SSL_VERIFYPEER] = 0;
    $headers[CURLOPT_SSL_VERIFYHOST] = 0;
  }
  elseif (substr($type, 0, 4) != 'http') {
    $url = 'http://'. $url;
  }
  $headers[CURLOPT_URL] = $url;
  $headers[CURLOPT_RETURNTRANSFER] = TRUE;
  $headers[CURLOPT_CONNECTTIMEOUT] = 10;
  $headers[CURLOPT_TIMEOUT] = 30;
  $headers[CURLOPT_FOLLOWLOCATION] = TRUE;
  $cookie_file = getcwd() . '/gr.tmp';
  $headers[CURLOPT_COOKIEFILE] = $cookie_file;
  $headers[CURLOPT_COOKIEJAR] = $cookie_file;

  $headers = $default_headers + $headers;

  static $ch;
  if (empty($ch)) $ch = curl_init();
  curl_setopt_array($ch, $headers);
  $output = trim(curl_exec($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curl_info = curl_getinfo($ch);
  if ($code == 200) {
    if ($close) {
      curl_close($ch);
      $ch = NULL;
    }
    return $output;
  }
  else {
    die(curl_error($ch));
    curl_close($ch);
    $ch = NULL;
  }
}

/**
 * Setup debugging for this call.
 */
function _goodreads_debug_setup() {
  global $gr_debug;
  if (!empty($gr_debug)) {
    $folder = './debug-' . date('Y-m-d_H_i_s');
    mkdir($folder);
    if (is_dir($folder)) {
      $gr_debug = $folder . '/';
      echo "Debug files in: $gr_debug\n";
    }
    else {
      $gr_debug = '';
    }
  }
}

/**
 * Store a file with the given output. Will put it in the debug folder.
 */
function _goodreads_debug($filename, $output) {
  global $gr_debug;
  if ($gr_debug) {
    file_put_contents($gr_debug . $filename, $output);
  }
}
