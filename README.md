# Sylius Timeline Plugin

[![Build](https://github.com/thibmonier/sylius-timeline-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-timeline-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**An order and customer timeline with internal staff notes for the Sylius 2 back office.**

The Sylius 2 order page has no history: the *History* page only lists address changes, the
customer's checkout note is read-only, and staff cannot leave a note on an order or a
customer. The Sylius 1 plugins that did it were never ported.

This plugin records what happens to an order (placed, paid, shipped, cancelled, refunded,
e-mails sent) and lets the team write internal notes, free and open source.

## Features

| Need | What the plugin does |
|---|---|
| Know what happened to an order, and who did it | **Timeline card** on the admin order page, newest first: order placed, order / payment / shipping state changes, each payment (method + amount) and shipment (method + tracking code), e-mails sent (with recipients). Each entry shows an icon, a label, the author (administrator name, *Customer* or *System*) and the date. |
| See the customer's checkout note in context | The checkout note becomes a *Customer note* entry at the time the order was placed. |
| Share information inside the team | **Internal notes** on orders and on customers: plain text up to 5,000 characters, line breaks kept, HTML escaped. Never shown in the shop. |
| Keep important information in sight | **Pin** a note: pinned notes are shown at the top of the order or customer page in a highlighted card. |
| Know the customer | **Customer timeline** on the admin customer page: customer notes and the key events of their orders (placed with number and total, cancelled, payments completed or refunded), last 50 entries with a link to the full paginated page. |
| Spot orders that need attention | **Notes badge** with the number of internal notes in the admin orders grid. |
| Install on a running shop | `tmonier:timeline:backfill` rebuilds a timeline for existing orders. |

Also included: translations in English, French, German, Spanish, Italian, Dutch and Polish;
Twig Hooks everywhere; MySQL, MariaDB and PostgreSQL support.

## Screenshots

![Order timeline](docs/images/admin-order-timeline.png)

![Customer timeline](docs/images/admin-customer-timeline.png)

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.2, 2.3 |
| Symfony | 6.4, 7.4, 8.0 |

The state machine abstraction must use the **Symfony Workflow** adapter (the Sylius 2 default,
`sylius_state_machine_abstraction.default_adapter: symfony_workflow`). Graphs mapped to the
Winzou adapter do not dispatch Symfony Workflow events and are not recorded.

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-timeline-plugin
    ```

2. Register the bundle in `config/bundles.php` (Symfony Flex does it automatically once a recipe exists):

    ```php
    Tmonier\SyliusTimelinePlugin\TmonierSyliusTimelinePlugin::class => ['all' => true],
    ```

3. Import the configuration in `config/packages/tmonier_sylius_timeline.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusTimelinePlugin/config/config.yaml" }
    ```

4. Import the admin routes in `config/routes/tmonier_sylius_timeline.yaml`:

    ```yaml
    tmonier_sylius_timeline:
        resource: "@TmonierSyliusTimelinePlugin/config/routes.yaml"
    ```

5. Run the migrations:

    ```bash
    bin/console doctrine:migrations:migrate
    ```

6. Optionally, give the orders placed before the installation a timeline:

    ```bash
    bin/console tmonier:timeline:backfill --dry-run
    bin/console tmonier:timeline:backfill
    ```

## Configuration

```yaml
# config/packages/tmonier_sylius_timeline.yaml
tmonier_sylius_timeline:
    record_emails: true          # e-mails sent by the Sylius mailer about an order
    enabled_workflows:           # default value
        - sylius_order
        - sylius_order_payment
        - sylius_order_shipping
        - sylius_payment
        - sylius_shipment
```

`enabled_workflows` accepts any Symfony workflow whose subject is an order, a payment or a
shipment, so your own workflows can be recorded too (they get a generic label, or your own
`tmonier_timeline.event.<workflow>.<transition>` translation). The bookkeeping transitions
applied while an order is placed (`request_payment`, `request_shipping`, payment `create` and
`process`, shipment `create`) are never recorded, and neither is anything happening to a cart.

The `TimelineEntry` resource can be extended like any Sylius resource
(`tmonier_sylius_timeline.resources.timeline_entry.classes.model`).

To hide the notes column of the orders grid:

```yaml
sylius_grid:
    grids:
        sylius_admin_order:
            fields:
                tmonier_timeline_notes:
                    enabled: false
```

## Customisation

The admin parts are built with [Twig Hooks](https://stack.sylius.com/twig-hooks/getting-started):

| Hook | Content |
|---|---|
| `sylius_admin.order.show.content.sections#left` → `tmonier_timeline_pinned_notes` | Pinned notes (top of the order page) |
| `sylius_admin.order.show.content.sections#left` → `tmonier_timeline` | Timeline card (bottom of the order page) |
| `sylius_admin.order.show.content.sections.tmonier_timeline` → `note_form`, `entries` | Note form and entries of the order timeline |
| `sylius_admin.customer.show.content.sections` → `tmonier_timeline_pinned_notes` | Pinned notes (top of the customer page) |
| `sylius_admin.customer.show.content.sections` → `tmonier_timeline` | Timeline card (bottom of the customer page) |
| `sylius_admin.customer.show.content.sections.tmonier_timeline` → `note_form`, `entries` | Note form and entries (`configuration.limit`, default 50) |
| `tmonier_timeline.admin.customer.timeline` | Full customer timeline page |

Templates can be overridden in `templates/bundles/TmonierSyliusTimelinePlugin/`. To record
your own events, use the services:

```php
public function __construct(
    private TimelineEntryFactoryInterface $entryFactory,
    private TimelineEntryWriterInterface $writer,
) {}

$entry = $this->entryFactory->createForOrder($order, TimelineEntryInterface::TYPE_TRANSITION, Actor::system());
$entry->setWorkflow('app_fraud_check');
$entry->setTransition('flag');
$this->writer->persist($entry); // flushed with your own changes
```

## How events are recorded

- **Workflow transitions**: a listener on `workflow.completed` records the transitions of the
  configured workflows. The entry is persisted **without flushing**, so it is saved by the flush
  that saves the state change itself, in the same transaction (and not at all if that change
  is rolled back). When the order is not persisted yet (fixtures, imports), the entry waits
  for the flush that inserts the order.
- **E-mails**: a listener on `sylius.email_send.post_send` records e-mails whose data contains
  an `order` (or a `shipment` / `payment` of an order). E-mails are sent after the application
  flush, so the entry is flushed right away, but only when nothing else is pending in the
  unit of work; otherwise it waits for the application's next flush.
- **Authors**: the authenticated administrator (name snapshot kept when the account is
  deleted), *Customer* for shop users and orders placed by guests, *System* otherwise
  (console, message consumers, payment notifications).
- **Failures never break a checkout**: recording errors are caught and logged on the
  `tmonier_timeline` channel.

## Notes rules

- Notes are plain text (HTML is escaped, line breaks are kept), 5,000 characters maximum.
- Any administrator can add, pin and unpin notes.
- Only the **author** can delete a note. When the author's admin account has been deleted,
  any administrator can.
- Notes cannot be edited: delete and write a new one.
- Every action is a CSRF-protected POST.

## GDPR — Personal data and customer erasure

### Data stored

The plugin stores the following personal data in `tmonier_timeline_entry`:

| Field | Where | What |
|---|---|---|
| `context.recipients` | `TYPE_EMAIL` entries | E-mail addresses of the recipients of each order-related e-mail |
| `author_name` | `TYPE_ORDER_PLACED`, `TYPE_CUSTOMER_NOTE` | Snapshot of the customer's full name at the time of the event |
| `content` | `TYPE_CUSTOMER_NOTE` | Checkout note left by the customer (verbatim from `Order::$notes`) |

Staff notes (`TYPE_NOTE`) are authored by administrators and are **not** considered personal
data of the customer. Their `content` is not erased.

When a customer is **deleted**, all their entries are removed by `ON DELETE CASCADE` on
`customer_id`. When a customer is **anonymised** (entity kept, personal data replaced), the
entries must be erased separately — see below.

### Right to erasure

Use `CustomerTimelineEraserInterface` to erase the customer's personal data from the timeline.
The eraser clears in a single query + single flush, without touching business records (entry
type, transition, date, order number, amounts, e-mail code, etc.):

```php
use Tmonier\SyliusTimelinePlugin\Gdpr\CustomerTimelineEraserInterface;

final class AnonymiseCustomerHandler
{
    public function __construct(
        private CustomerTimelineEraserInterface $timelineEraser,
        // … your other services
    ) {}

    public function __invoke(AnonymiseCustomer $command): void
    {
        $customer = $this->findCustomer($command->customerId);

        // 1. Your existing anonymisation logic (replace e-mail, name, address…).
        $this->customerAnonymiser->anonymise($customer);

        // 2. Erase personal data from the timeline.
        $this->timelineEraser->eraseForCustomer($customer);
    }
}
```

### Sylius core — anonymisation hook

Sylius 2.2 / 2.3 does **not** provide a built-in anonymisation event or service
(`CustomerAnonymizer`, `sylius.customer.anonymise`, etc.). There is no automatic hook to
attach the eraser to. You must call `CustomerTimelineEraserInterface::eraseForCustomer()`
explicitly from your own anonymisation process (command, message handler, controller…).

### Console command

```bash
# Preview (no write)
bin/console tmonier:timeline:erase-customer 42 --dry-run
bin/console tmonier:timeline:erase-customer jane@example.com --dry-run

# Erase
bin/console tmonier:timeline:erase-customer 42
bin/console tmonier:timeline:erase-customer jane@example.com
```

The command accepts either a numeric customer ID or an e-mail address.

## Limitations

- Changes made outside Symfony Workflow (direct SQL, Winzou-mapped graphs, setters without a
  transition) are not recorded. Address changes stay in the Sylius *History* page.
- The backfill can only rebuild what an order still tells: placement (with the customer's
  checkout note), completed payments, shipped shipments and cancellation. Dates of payments
  and cancellations are approximated from `updatedAt`.
- Entries record facts with a snapshot (payment method name, amount, tracking code); they are
  not updated when the order is edited later.
- Sylius Plus RBAC is not integrated: every administrator can see and write notes.

## Development

```bash
docker compose up -d mysql   # or your own MySQL
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n

vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/phpunit --testsuite non-unit   # needs a test database with fixtures
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

## License

MIT. See [LICENSE](LICENSE).
