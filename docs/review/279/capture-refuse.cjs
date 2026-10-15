// #279 prep skeleton. Refuses unless a live disposable site URL is set.
// Never copies wordpress/assets/screenshot-*.png. Never writes a PNG.
const http = require('node:http');
const https = require('node:https');
const path = require('node:path');

const OLD = [
  'wordpress/assets/screenshot-1.png',
  'wordpress/assets/screenshot-2.png',
  'wordpress/assets/screenshot-3.png',
  'wordpress/assets/screenshot-4.png',
  'wordpress/assets/screenshot-5.png',
  'wordpress/assets/screenshot-6.png',
];

function die(message) {
  console.error('listing-capture-279: ' + message);
  process.exitCode = 1;
}

function looksLikeCopy(value) {
  const text = String(value);
  return OLD.some((asset) => text.includes(asset) || text.includes(path.basename(asset)))
    || /copy|screenshot-\d\.png/i.test(text);
}

const args = process.argv.slice(2);
if (process.env.WL279_COPY_OLD_ASSETS || args.some((arg) => looksLikeCopy(arg))) {
  die('refusing to copy old listing assets');
  return;
}

const base = process.env.WL279_BASE_URL;
if (!base) {
  die('refusing: WL279_BASE_URL (live disposable site) is required');
  return;
}

let url;
try {
  url = new URL(base);
} catch (error) {
  die('refusing: WL279_BASE_URL is not a URL');
  return;
}
if (url.protocol !== 'http:' && url.protocol !== 'https:') {
  die('refusing: WL279_BASE_URL must be http or https');
  return;
}

const client = url.protocol === 'https:' ? https : http;
const request = client.request(url, { method: 'GET', timeout: 5000 }, (response) => {
  response.resume();
  if (response.statusCode < 200 || response.statusCode >= 400) {
    die('refusing: WL279_BASE_URL is not a live site (HTTP ' + response.statusCode + ')');
    return;
  }
  die('refusing to write PNGs. Shots 4 and 5 are BLOCKED until #281 is on the branch. Do not copy wordpress/assets/screenshot-*.png.');
});
request.on('timeout', () => {
  request.destroy();
  die('refusing: WL279_BASE_URL did not respond');
});
request.on('error', () => {
  die('refusing: WL279_BASE_URL is not a live site');
});
request.end();
