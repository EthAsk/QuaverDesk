# Modules

Optional features that live in their own folder here, outside the core. The public
release ships with none; the app runs the same without them.

A module is a folder `modules/<Name>/` with a service provider at
`modules/<Name>/<Name>ServiceProvider.php` (namespace `Modules\<Name>`). It is found and
registered automatically (`App\Support\Modules::discover`), and hooks in through
`App\Support\Modules`:

| Hook | What it adds |
| --- | --- |
| `feature()` | A paid feature, on for a plan and every plan above it. Works with `feature:` route middleware and `@feature` in views. |
| `adminNav()` | A page in one of the admin menu groups. |
| `homeCard()` | A card on everyone's Home page. |
| `sipUsers()`, `sipUserTenant()` | Devices that sign in to the voice server. |
| `outgoingCall()` | Routing for calls those devices place. |
| `tenantModels()` | Records a company owns (exported and deleted with it). |

Inside the folder, a module keeps its own routes, views (`<name>::…`), migrations, front-end
scripts (`resources/js/*.js`, bundled automatically) and tests (`Tests/*Test.php`, run with the
rest of the suite).
