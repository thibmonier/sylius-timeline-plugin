# Functional specification — order and customer timeline

The Sylius 2 admin order page has no timeline (its *History* page only lists address changes),
the customer's checkout note is read-only, and staff cannot leave internal notes on orders or
customers. Sylius 1 plugins covering this (order history, order comments) were not ported.

## 1. Scope (v1)

| # | Feature | Summary |
|---|---|---|
| 1 | Order timeline | Card on the admin order page, newest first: order placed, state transitions of the order, of its payment and shipping states, of each payment and shipment, e-mails sent |
| 2 | Internal notes | Staff-only notes on orders and customers: add, pin / unpin, delete (author only) |
| 3 | Customer timeline | Card on the admin customer page (last 50) + full paginated page |
| 4 | Orders grid indicator | Number of internal notes per order |
| 5 | Backfill | `tmonier:timeline:backfill [--dry-run] [--batch-size=100]` |
| 6 | Translations | en, fr, de, es, it, nl, pl |

Out of scope: editing notes, mentions / notifications, attachments, API Platform endpoints,
showing anything to the customer, Sylius Plus RBAC.

## 2. Data model

Single resource `TimelineEntry`, table `tmonier_timeline_entry`:

| Column | Notes |
|---|---|
| `order_id` | nullable, `ON DELETE CASCADE` |
| `customer_id` | nullable, `ON DELETE CASCADE`. Order entries also carry the order's customer so that the customer timeline is one query |
| `type` | `order_placed`, `customer_note`, `order_state`, `payment_state`, `shipping_state`, `payment`, `shipment`, `transition` (other configured workflow), `email`, `note` |
| `workflow`, `transition`, `from_state`, `to_state` | for workflow entries |
| `context` | JSON snapshot: order number / total / currency, payment method / amount, shipping method / tracking, e-mail code / recipients, `backfilled` |
| `content` | text of notes and of the customer's checkout note |
| `pinned` | notes only |
| `author_type` | `admin`, `customer`, `system` |
| `admin_user_id` | nullable, `ON DELETE SET NULL` |
| `author_name` | snapshot of the author's name |
| `created_at` | |

Indexes: `(order_id, created_at)`, `(customer_id, created_at)`, `admin_user_id`. The migration
uses the Schema API (MySQL, MariaDB, PostgreSQL) and only creates this table.

## 3. Recording

### 3.1 Workflow transitions

Listener on `workflow.completed`. Recorded when the workflow is in `enabled_workflows`
(default: `sylius_order`, `sylius_order_payment`, `sylius_order_shipping`, `sylius_payment`,
`sylius_shipment`) and the subject is an order, or a payment / shipment of an order.

| Workflow | Recorded transitions | Ignored |
|---|---|---|
| `sylius_order` | `create` → *Order placed* (+ *Customer note* when `Order::$notes` is filled), `cancel`, `fulfill` | — |
| `sylius_order_payment` | all | `request_payment` |
| `sylius_order_shipping` | all | `request_shipping` |
| `sylius_payment` | all (complete, fail, cancel, refund, authorize…) with method + amount | `create`, `process` |
| `sylius_shipment` | all (ship, cancel) with method + tracking code | `create` |

Orders in the `cart` state are never recorded.

Persistence: the entry is persisted without flush and is saved by the flush of the state
change (same transaction). If the order is not managed yet, the entry is queued and joins the
flush that inserts the order (Doctrine `onFlush`); the queue is reset between requests.

### 3.2 E-mails

Listener on `sylius.email_send.post_send` (`SyliusMailerEvents::EMAIL_POST_SEND`). Recorded
when the e-mail data contains an `order`, or a `shipment` / `payment` of an order, and the
order is persisted. Stored: e-mail code and recipients. Because e-mails are sent after the
application flush, the entry is flushed immediately, but only when it is the only pending
change of the unit of work. Can be disabled with `record_emails: false`.

### 3.3 Author

- authenticated admin user → `admin` + name snapshot (first + last name, or e-mail);
- authenticated shop user → `customer` + customer name;
- nobody: `customer` for *Order placed* (guest checkout), `system` otherwise.

### 3.4 Robustness

Recording must never break a checkout or a back-office action: listener errors are caught and
logged (`tmonier_timeline` channel). Database errors at flush time are not caught (same
transaction as the state change), as for any other entity.

## 4. Back office

### 4.1 Order page

- Pinned notes card at the top of the main column (yellow).
- Timeline card at the bottom of the main column: note form (textarea, CSRF token) then
  entries, newest first.

### 4.2 Customer page

- Pinned customer notes above the order statistics.
- Timeline card at the bottom: note form, customer notes and key order events
  (*Order placed* with number linking to the order and total, *Order cancelled*,
  *Payment completed*, *Payment refunded*). Order notes are not shown there.
- Last 50 entries; "Show the full timeline (N entries)" links to
  `/admin/customers/{id}/timeline` (50 per page).

### 4.3 Notes

| Action | Route | Rule |
|---|---|---|
| Add to an order | `POST /admin/orders/{id}/timeline/notes` | any administrator |
| Add to a customer | `POST /admin/customers/{id}/timeline/notes` | any administrator |
| Pin / unpin | `POST /admin/timeline/notes/{id}/pin` | any administrator |
| Delete | `POST /admin/timeline/notes/{id}/delete` | author only (403 otherwise); any administrator when the author's account no longer exists |

- Content trimmed, CRLF normalised, 1–5,000 characters (flash error otherwise).
- Rendered HTML-escaped with `nl2br`.
- Invalid CSRF token → 400. Not an administrator → redirected to the admin login (firewall)
  or 403.
- Notes are never rendered in the shop.

### 4.4 Orders grid

Field `tmonier_timeline_notes` added to `sylius_admin_order` through the grid configuration:
badge with the number of notes (one indexed `COUNT` per row), linking to the timeline.

## 5. Backfill

`tmonier:timeline:backfill [--dry-run] [--batch-size=100]` processes placed orders
(`checkoutCompletedAt` not null) **without any entry**, by ascending id, flushing and clearing
the entity manager after each batch. Created entries (context `backfilled: true`):

- *Order placed* at `checkoutCompletedAt`, author *Customer*, plus *Customer note*;
- *Payment completed* for each completed payment, at `updatedAt`;
- *Shipment shipped* for each shipment with `shippedAt`;
- *Order cancelled* for cancelled orders, at `updatedAt`.

Dates are never earlier than the placement. Running the command again is safe (orders with
entries are skipped).
