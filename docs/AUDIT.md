# Audit qualité — sylius-timeline-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`. Revue qualitative par l'orchestrateur (lecture directe des sources).

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 52 tests, 972 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 13 fichiers avec suggestions |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 paquets abandonnés (pas de CVE) |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 13 fichiers signalés relèvent principalement de la règle `TypedPropertyFromCreateMockAssignRector` (propriétés de mocks dans les tests) — suggestions de modernisation non bloquantes, non appliquées. Lancer `make rector-fix` puis relancer ECS/PHPStan/tests pour les appliquer.

**Paquets abandonnés** : `behat/transliterator` et `php-http/message-factory` sont des dépendances transitives de `sylius/sylius`, hors du périmètre du plugin. Aucun CVE identifié.

## 2. Audit qualitatif (revue par l'orchestrateur)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | `declare(strict_types=1)` + interfaces partout, DIP strict, `final` sur toutes les classes de service. `TimelineEntry` volontairement non-`final` (extension Sylius) et ses propriétés `protected` (pattern standard Sylius). |
| Intégration Symfony / Sylius | **8/10** | Listeners avec swallowing d'erreurs (jamais bloquants), CSRF sur toutes les écritures, Twig autoescape, `TimelineEntryWriter` qui gère le cas "order non encore persisté" via `onFlush`. |
| Sécurité (OWASP 2025) | **9/10** | Toutes les routes admin protégées par le firewall + double vérification `getAdminUser()`, CSRF systématique, `|nl2br` sur contenu autoescapé (pas de `|raw`), DQL 100 % paramétré. Risques résiduels mineurs. |

### Points forts

- Architecture propre : chaque classe a une responsabilité unique, toutes les dépendances injectées via interfaces — idéal pour les tests et la substitution.
- `TimelineEntryWriter` résout élégamment le problème des entrées d'ordres non encore persistés via une queue `pending` vidée dans `onFlush`, sans cascade-persist et sans flush parasite.
- `WorkflowTimelineListener` et `EmailTimelineListener` catch tous les `\Throwable` et loggent sans propager — un enregistrement raté ne casse jamais un checkout.
- Sécurité XSS irréprochable : les notes sont affichées avec `|nl2br` sur du contenu autoescapé (Twig), conformément à la règle documentée dans `CLAUDE.md`. Aucun `|raw` dans les templates.
- CSRF complet : token unique par entrée pour les actions delete/pin (`noteCsrfTokenId()`), token fixe pour l'ajout. Validé côté serveur avant toute action.
- `deleteAction` vérifie l'auteur : seul l'auteur peut supprimer, ou tout admin si le compte de l'auteur est supprimé — comportement documenté et intentionnel.
- DQL paramétré partout dans `TimelineEntryRepository` et `TimelineBackfiller`.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Accès au détail de la timeline sans vérification du périmètre** (`TimelineNoteController.php:124-140`, OWASP A01:2025). `customerTimelineAction` et `addOrderNoteAction` récupèrent l'entité par ID sans vérifier que le customer/order appartient à un périmètre spécifique (canal, organisation). Actuellement tous les admins voient toutes les timelines — acceptable en mono-tenant, mais à documenter explicitement et à durcir si le projet devient multi-tenant.

2. **`TimelineEntryWriter.save()` inspecte directement l'UnitOfWork** (`TimelineEntryWriter.php:57-64`). La vérification `isAlone` compare `[$entry]` aux insertions schedulées. Si Doctrine change l'ordre des insertions ou ajoute une insertion intermédiaire (collecte d'événements, décorateur), l'email ne sera pas flushé. → Envisager un flush conditionnel via un événement de kernel (`kernel.terminate`) plutôt qu'une inspection directe de l'UoW.

3. **Adresses email dans le contexte persisté** (`TimelineRecorder.php:97-100`, OWASP A02:2025). Les adresses email des destinataires sont stockées en clair dans `context.recipients` (champ JSON) et affichées dans l'admin. C'est intentionnel mais non documenté : préciser dans `SPEC.md` et `README.md` les implications RGPD (rétention, droit à l'oubli sur les entrées mail).

### 🟡 Mineur

- `TimelineRecorder::recordEmail()` accepte `array $data` non typé (`TimelineRecorder.php:85`). Si `$data['order']` est présent mais n'est pas un objet compatible, `resolveOrder()` retourne `null` silencieusement. → Ajouter un `is_object()` guard ou affiner le typage.
- `TimelineEntry::getId()` retourne `?int` mais `noteCsrfTokenId()` caste en `(int)` sans vérifier que `$entry->getId()` n'est pas `null` (`TimelineNoteController.php:50`). Si l'entrée n'est pas encore persistée, le token CSRF serait `tmonier_timeline_note_0` — pas un vrai risque (l'entrée doit exister pour l'action), mais mérite un assert.
- `TimelineExtension::CUSTOMER_SHOW_LIMIT` (50) et `TimelineNoteController::CUSTOMER_TIMELINE_PER_PAGE` (50) sont deux constantes séparées avec la même valeur. → Unifier en une constante partagée dans `TimelineEntryRepositoryInterface`.
- Appliquer les 13 suggestions Rector (`make rector-fix`) — concerne principalement les propriétés de mocks dans les tests, sans impact fonctionnel.
- Confirmer la compatibilité des API Sylius lors du passage à 2.3 : `EmailSendEvent`, `SyliusMailerEvents`, `PrependDoctrineMigrationsTrait`.

### ℹ️ Pour information (choix de conception assumés)

- `TimelineEntry` n'est pas `final` : la classe entity est conçue pour être étendue par le projet hôte (pattern Sylius standard, mapping XML, `mapped superclass`). Les propriétés sont `protected` pour permettre cette extension.
- Le swallowing d'erreurs dans les listeners est volontaire : un enregistrement de timeline raté ne doit jamais interrompre une transition métier.
- La règle "Notes are rendered with `|nl2br` on autoescaped content: never `|raw`" est correctement implémentée dans tous les templates.

## 4. Verdict

Plugin **très propre et discipline exemplaire** : outillage au vert (ECS, PHPStan level max, 52 tests unitaires, 972 assertions), typage strict, listeners défensifs, sécurité XSS/CSRF irréprochable. Les axes d'amélioration principaux sont la documentation des implications RGPD des adresses mail stockées, le risque de flush manqué dans `TimelineEntryWriter.save()` et la préparation multi-tenant du contrôle d'accès — aucun n'est bloquant pour une release actuelle en mono-tenant.
