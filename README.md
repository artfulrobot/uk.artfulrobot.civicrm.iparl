# iParl integration for CiviCRM

This extension works with [Organic Campaigns' iParl service](http://www.organiccampaigns.com/). That service allows people to email their MPs (MEPs, etc.).

Once set-up, people taking action through iParl will have their detailed entered
in your CiviCRM database along with an activity recording that they took action.

It requires a fair bit of set-up between your CiviCRM and your Organic Campaigns
account, including some manual "oh, would you mind setting this up please
Organic Campaigns?", "No, not a problem" sort of non-technical stuff.

## Installing the extension

You'll need a functioning iParl account before starting.

Install the extension in the normal way. A pop-up will tell you to go to
`civicrm/admin/setting/iparl` to configure things. That page has the instructions.
**You can also find this link under the Administer » iParl Settings**

You can test it like
```sh
curl -k -L 'https://yoursite.org/civicrm/iparl-webhook' \
     -d secret=helloHorseHeadLikeYourJumper \
     -d name=Jo \
     -d surname=Bloggs \
     -d email=jo@example.com \
     -d actionid=1
```

or, with httpie

```sh
http --verify no -f POST 'https://yoursite.org/civicrm/iparl-webhook' \
     secret=helloHorseHeadLikeYourJumper \
     name=Jo \
     lastname=Bloggs \
     email=jo@example.com \
     actionid=1
```

Where your-webhook-url you can get from the settings page (it's basically your
domain `/civicrm/iparl-webhook`) and your secret must match.

If it works, you should simply see `OK`. If it doesn't work, check your server
logs. If you enabled logging on the iParl extension's settings page, you'll find
the file in CiviCRM's ConfigAndLog directory. Hint: it has iparl in the name.

First time you call it you should see a new contact created. Second time you
should just see another activity added to that contact's record.

## Configuring your iParl Actions

Since version 1.3, this extension will handle single name fields, but please do
not do this. It's not possible to separate a set of names into first and last
names, better to ask users to do this; it's their personal data.

## Performance and caching

When a user submits an iParl petition, iParl sends the data to CiviCRM as a
webhook. This extension receives that data, looks up (or creates) the contact,
updates the record with an activity etc. In creating the activity, it needs to
look up the action ID sent by iParl by making another query to iParl. For some
reason (Aug 2019) this is hideously slow - near 10s - and while this is going on
the user is left waiting.

For this reason this extension caches this data (i.e. keeps its own copy). Only
when the copy is older than 1 hour will it reload. Because this is still likely
to be a problem for the poor user who stumbles upon the petition at that time, a
scheduled job runs hourly to refresh the cache, reducing the chance a user gets
hit by this.

If you have made a new petition/changed a petition you may need/want to forcibly
refresh the cache. You can do this by visiting the extension's settings page and
simply pressing Save. As well as saving the settings, it reloads the cache.

## Webhook processing

Since v1.4.0 webhooks are not processed in real time but are instead added to a
queue. The queue is processed by a new Scheduled Job. By default this scheduled
job is set up so that it runs every time Cron fires, and that it does a maximum
of 10 minutes' procesing of the iParl queue at once. This should be ample time
for most sites. You can change the schedule and the maximum execution time (in
seconds) from the Scheduled Jobs admin page.

### Warnings about failed webhooks

Sometimes iParl sends us data that is not valid for our use case. e.g.
a spammer enters a sentence about their wares into an address field and it's so
long it won't fit.

From v1.5.0 (see changelog below) these entries will be put in a new queue that
never gets processed. The iParl log file will contain details of what the problem
was from the intial processing.

If any of these are found, the System Status page will show warnings.

If you get one or two, you might choose to ignore these. If you get lots then
you will need a technical person to inspect the dedicated log file created by
this extension in the ConfigAndLog directory to see what is causing the
problems. They can then add code to handle those situations better (please
submit a Pull Request back to the project if you do make improvements), or
you can [commission me to do this work](https://artfulrobot.uk/contact).

Note that the failure could also come from outside this extension, e.g. any
custom processing you have put in place, e.g. using the provided hook, or
additional features like CiviRules.

If you intend to ignore these you can hide the System Status message in the normal way.

Technically, you will need to do one of the following (after taking a backup):

1. Delete the problem submissions by running this SQL:  
   `DELETE FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks-failed';`

2. Add the problem submissions back on the queue (e.g. if you believe they will
   work now) by running this SQL:  
   `UPDATE civicrm_queue_item SET queue_name = 'iparl-webhooks' WHERE queue_name = 'iparl-webhooks-failed';`
   (If they fail *again* then they will be recreated as a
   `iparl-webhooks-failed` record again.)


## Developers wanting to contribute to the project

Hi! Thanks! Let’s make this better. If you add features (or fix bugs) please (a) run existing tests, (b) add new/update tests. The tests run with phpunit8 (and phpunit7, currently). You'll need a buildkit environment to run the tests; you can’t run tests on a normal site.

## Developers needing to customise the processing

You can interfere with the webhook processing at the queuing stage and the processing stage, by listening for some Symfony events in your custom extension.

If you’re new to this, I think I have provided a reasonable explanation of how to work with Symfony events in my [Stack Exchange answer](https://civicrm.stackexchange.com/a/39948/35) and there’s example code here, too. The code below assumes your extension is called `myext` and sets up listeners for each event with simple functions, examples of which are included in the following two sections.

In your main extension file, `myext.php`:

```php
<?php
/**
 * Implements hook_civicrm_container().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 */
function myext_civicrm_container($container) {
  // https://docs.civicrm.org/dev/en/latest/hooks/usage/symfony/
  $container->findDefinition('dispatcher')

  // To alter the initial receive-and-queue process:
  ->addMethodCall('addListener', [
    'civi.iparl.receive',
    'myext_iparl_receive_alter']
  )
  // To alter the processing of queued items:
  ->addMethodCall('addListener', [
    'civi.iparl.process',
    'myext_iparl_process_alter']
  );
}
```

### Webhook received event

This event (`civi.iparl.receive` or `\Civi\Iparl\WebhookProcessor::RECEIVE_EVENT`) has the following properties:

- `$event->raw` the raw data array
- `$event->chain` array of callables that will be used to process the data before it is queued.

The `chain` is an ordered associative array. The keys that this extension provides are: `checkRequiredFields` (rejects if email or webhook secret is missing), `checkSecret` (rejects if secret does not match configured value), `firewallNames` (strips some emoji and rejects if URLs found in name fields). These keys are provided so that you could easily remove or replace that part of the processing. You can add tasks to the end, too, and it would be feasible to add tasks in the middle somewhere if you really needed to.

Let's say you wanted to remove the firewallNames filter because you think people should be able to change their name to a URL these days (?!), and let's say you want to add another filter that rejects any emails from knownspammer.net domain.

In your extension:
```php
<?php

function myext_iparl_receive_alter(\Civi\Core\Event\GenericHookEvent $event) {
    // do without the firewall bit.
    unset($event->chain['firewallNames']);
    // Add our own function to remove known spammer
    $event->chain[] = 'myext_iparl_reject_spam_domain';
}

function myext_iparl_reject_spam_domain(array &$data) {
  $email = $data['email'] ?? '';
  if (preg_match('/@knownspammer.net$/', $email)) {
    // Don't queue this one:
    throw new InvalidArgumentException("Rejecting knownspammer email: '$email'");
  }

  // Nb. we can, should we wish, add/alter extra data here too,
  // e.g. if you later implemented something in the process event below.
  // $data['hello'] = 'world';
}
```

### Webhook process event

This event (`civi.iparl.process` or `\Civi\Iparl\WebhookProcessor::PROCESS_EVENT`) has only the `chain` property, which as with the receive event above, is an array of callables that will be used to process the queued data.

The chain comes with the following:

- `parseNames` Ensures the data has `first_name` and `last_name` by splitting if needed.
- `findOrCreate` Ensures the data has `contactID` (used by all following processes).
- `mergePhone` Ensures the phone number is applied to the contact record.
- `mergeAddress` Ensures the address is applied to the contact record.
- `recordActivity` Records the iParl activity
- `legacyHook` For backwards compatibility, this implements the original post-process data hook (see below)

For our example, let's say you want to use [xcm](https://github.com/systopia/de.systopia.xcm/) to process the contact data instead of the `findOrCreate`, `mergePhone` and `mergeAddress` methods provided by this extension. And let's say you want to add signers to a group, too.

```php
<?php
function myext_iparl_process_alter(\Civi\Core\Event\GenericHookEvent $event) {
  // Replace the default findOrCreate method with our own that also handles phones, addresses.
  $event->chain['findOrCreate'] = 'myext_custom_find_or_create';
  unset($event->chain['mergePhone']);
  unset($event->chain['mergeAddress']);
  // add our add to group function, too
  $event->chain['addToGroup'] = 'myext_custom_add_to_group';
}

function myext_custom_find_or_create(&$data) {
  $contactID = civicrm_api3('Contact', 'getorcreate', $data);
  // Remember to set the contactID key, it's required by other processes:
  $data['contactID'] = $contactID;

  // Note: if we throw an exception here, the process will not proceed and the queued webhook will be re-queued as a failed webhook event which will need manual intervention.
}

function myext_custom_add_to_group(&$data) {
    $contactIDs = [$data['contactID']];
    CRM_Contact_BAO_GroupContact::addContactsToGroup(
      $contactIDs,
      OUR_NEWSLETTER_GROUP_ID);
}
```

### Deprecated hook (works in 1.3 - 1.6)

There's now (since 1.3) a hook you can use to do your own processing of the
incoming webhook data (e.g. check/record consent and add to groups).

Example: if your custom extension is called `myext` then write a function like
this:

    /**
     * My custom business logic.
     *
     * Implements hook_civicrm_iparl_webhook_post_process
     *
     * @param array $contact The contact that the iParl extension ocreated/updated.
     * @param array $activity The activity that the iParl extension created.
     * @param array $webhook_data The raw data.
     */
    function myext_civicrm_iparl_webhook_post_process(
      $contact, $activity, $webhook_data) {

      // ... your work here ...
    }


## About

This was written by Rich Lott ([Artful Robot](https://artfulrobot.uk)) who
stitches together open source tech for people who want to change the world. It
has been funded by the Equality Trust and We Own It.

Futher pull requests welcome :-)

## Changelog

### Version 1.6.0 (potentially breaking changes)

This version involved a big refactor of the code. This should not affect functionality, and should slightly improve efficiency, but the primary reason was to enable better local customisations.

Changes:

- improved caching of petition data.
- deprecated `hook_civicrm_iparl_webhook_post_process`
- new Symfony events allow customisation pre-accepting data from iParl, and to allow full customisation of how the queued data is processed.
- includes filters that remove emojis from names, and reject any webhook that has URLs in the name fields - this is a known spam attack vector, as many set-ups have a thank you email that outputs the name in the thank you email, thereby enabling a spammer to inject their link in your email.
- If calling the iParl API to get titles of actions (e.g. `https://iparlsetup.com/api/<your_user_id>/actions.xml`) fails (and experience says this can happen not infrequently), or if it's empty (e.g. an admin mistakenly marked a petition not-public which removes it from these API results) then the queued webhook will fail and the queue will be aborted. Previously we did this check before failing the first one, however for orgs that don't use one of the action types (petition, action) this could have caused problems.




### Version 1.5.0

- Queued webhooks that cause a crash (e.g. extra long data in address fields or
  such) will no longer cause the entire queue to hang. Instead they will be
  requeued under a queue name of `iparl-webhooks-failed`. See "Warnings about
  failed webhooks" above.

- Where a webhook includes a valid date time (e.g. `2020-10-01 12:34:56`) it
  will be used for the activity date. This matters for the cases where there's
  a delay between receiving the webhook and processing it.


### Version 1.4.0

- Processing webhooks is now deferred to a queue. This means that the user sees
  confirmation that their action has been successfully taken quicker (because
  webhooks are fired in sync and real time by iParl). It also hopes to avoid a
  deadlock situation that can occur on busy sites when two (or more) processes
  start creating Contacts, Activities etc. at the same time. Webhooks are now
  stored in a queue and a Scheduled Job is set up to process the queue. By
  ensuring only one process accesses the queue at once, this should help
  avoid deadlocks. Depending on how often cron fires, it does mean there may be
  a delay.

- tested on CiviCRM 5.15


### Version 1.3.2

- tested on CiviCRM 5.15

- iParl lookups now cached for 1 hour (you can still force a refresh by saving
  the settings form).

- New scheduled job runs hourly to keep the cache up to date.

- Settings form now moved to `civicrm/admin/setting/iparl` which is more
  standard (find it under **System Settings** in the menu)

- New hook for developers (see *Developers* below) to do more processing of
  incoming data.

- updated URLs for iParl's API for fetching titles (etc.) of actions, petitions
   (again)

### Version 1.2

- works on CiviCRM 5.9 (and possibly NOT on earlier versions)

- updated URLs for iParl's API for fetching titles (etc.) of actions, petitions.

- iParl lookups are now cached for 10 minutes; will speed up processing.
  However, if you add a new petition/action and test it immediately there's a
  chance the name won't pull through. You can force the cache to clear by
  visiting the iParl Extension's settings page (under the Administer menu)

- System status checks (Administer » Administration » System Status) now check
  for missing username/webhook key and check that the API can be used to
  download data.

- Basic phpunit tests created.

### Version 1.1

Adds support for iParl "Petition" actions (v1 just worked with "Lobby Actions").
