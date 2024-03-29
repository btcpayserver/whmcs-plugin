<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2015 BitPay
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

global $bpOptions;

// Please look carefully through these options and adjust according to your installation.
// Alternatively, any of these options can be dynamically set upon calling the functions in bp_lib.

// REQUIRED Api key you created on your BTCPay Server store.
// example: $bpOptions['apiKey'] = 'L21K5IIUG3IN2J3';
$bpOptions['apiKey'] = '';

// URI to your BTCPay server instance.
$bpOptions['btcpayUrl'] = '';

// whether to verify POS data by hashing above api key.  If set to false, you should
// have some way of verifying that callback data comes from BTCPay Server.
$bpOptions['verifyPos'] = true;

// email where invoice update notifications should be sent
$bpOptions['notificationEmail'] = '';

// url where BTCPay Server should send update notifications.  See API doc for more details.
# example: $bpNotificationUrl = 'http://www.example.com/callback.php';
$bpOptions['notificationURL'] = '';

// url where the customer should be directed to after paying for the order
# example: $bpNotificationUrl = 'http://www.example.com/confirmation.php';
$bpOptions['redirectURL'] = '';

// This is the currency used for the price setting.
$bpOptions['currency'] = 'BTC';

// Indicates whether anything is to be shipped with
// the order (if false, the buyer will be informed that nothing is
// to be shipped)
$bpOptions['physical'] = 'true';

// If set to false, then notificaitions are only
// sent when an invoice is confirmed (according the the
// transactionSpeed setting). If set to true, then a notification
// will be sent on every status change
$bpOptions['fullNotifications'] = 'true';

// transaction speed: low/medium/high.   See API docs for more details.
$bpOptions['transactionSpeed'] = 'medium';
