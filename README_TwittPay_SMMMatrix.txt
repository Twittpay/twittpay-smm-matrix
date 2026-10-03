===========================================================================
 TWITTPAY - SMM Matrix payment gateway
===========================================================================

 WHERE IT GOES
   Extract this zip at your SMM Matrix root - the folder that has app/ and
   artisan in it. These files land in place:

     app/Services/Gateway/twittpay/Payment.php
     assets/images/gateway/twittpay.png

   database.sql and this README sit at the top of the zip. They are not part of
   the panel - delete them from the server after you are done, or do not upload
   them at all.

 INSTALL - FOUR STEPS

   1. Upload and extract at the panel root as above.

   2. Import database.sql in phpMyAdmin, into your panel's database. It adds one
      row to the `gateways` table and touches nothing else. The row is added
      switched off on purpose.

   3. Open app/Http/Middleware/VerifyCsrfToken.php and add this line inside the
      $except array:

        'payment/twittpay/*',

      Your $except array will then look something like:

        protected $except = [
            '*save-token*',
            '*sort-payment-methods*',
            '*admin/upload/ck/image*',
            'payment/twittpay/*',
        ];

      The gateway's server posts the webhook from outside the browser, so it has
      no CSRF token to send.

   4. Admin -> Payment Gateways -> open "Bkash/Nagad/Rocket/Upay" and fill in:


        Brand Key           from your gateway dashboard, under Brands

      Set the BDT rate, minimum and maximum in the currency block, switch the
      gateway on, and make a small test deposit.

 HOW IT WORKS
   * The user picks the method, types an amount and pays. The panel works out the
     BDT amount from the conversion rate in the gateway's own currency settings,
     so the amount sent is already in BDT.
   * Both the returning user and the gateway's webhook come back to the panel's
     ipn route for this gateway.
   * Nothing on that request is trusted. The transaction id is read from it and
     the payment is then verified against the API.
   * COMPLETED credits the deposit, but only if the deposit is not already paid -
     so the webhook and the return cannot credit it twice.
   * PENDING credits nothing. The user has sent the money and your merchant has
     not approved it. They are told it is being checked and sent back to the Add
     Funds page. The gateway calls again with the answer, and that call credits
     the balance. Do not ask them to pay twice.
   * A payment worth less than the deposit is refused.

 WHAT TO WATCH
   * The ipn route must be reachable from the internet. Your gateway's server
     calls it directly.
   * This module sends the user back to the ipn route as well, so a deposit still
     completes if the webhook cannot get through. If your panel's ipn route only
     accepts POST, change 'success_url' in Payment.php to route('user.add.fund')
     and the webhook will do all the work on its own.
   * The gateway only takes BDT. The BDT row in the gateway's currency settings is
     what the panel converts with - keep its conversion rate up to date.
   * Refunds are not done through the API. Refund on the gateway side, then adjust
     the user's balance by hand.

 FIXES OVER THE ORIGINAL
   * The PipraPay version compared an Brand Key sent in a webhook header. This
     gateway's webhook is not signed and sends no key, so that check would have
     refused every real call. It is gone - verification against the API does the
     job, because a made-up transaction id simply does not verify.
   * The original credited the deposit every time a completed webhook arrived,
     with no check that it was already paid. It now checks first.
   * The original never compared the amount paid with the amount due. It does now.
   * A pending payment was reported as a failure. It is now left alone for the next
     webhook.
   * The original read the transaction id straight out of the raw request body,
     which is empty when the user comes back with a GET. The id is now read from
     the query, a form body or a JSON body.
   * The original passed the raw API error message back to the user. An error
     string can carry your Brand Key back out, so this port shows a plain message.
   * The gateway row is inserted with a NULL id instead of a hardcoded id 55, so
     it cannot collide with a row you already have.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code. PHP itself was NOT run - there is no PHP binary on the machine this was
   built on, so php -l was never executed. Test it on a staging install first.
