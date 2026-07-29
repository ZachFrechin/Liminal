# Liminal

ERP open source modulaire en PHP 8.4. Micro-noyau maison, briques PSR, sans framework.

## Le modèle à trois primitives

Tout dans Liminal est l'une de ces trois choses, et rien d'autre :

| Primitive | Rôle | Exemples |
|---|---|---|
| **Lib** | Une capacité technique. Sans route, sans page, sans donnée métier. Livrée avec le cœur. | `database`, `security`, `rendering`, `api` |
| **Module** | Une verticale métier : pages, logique, données. Consomme les libs. Installable, activable par société. | `authentication`, `thirdparty` |
| **Registre** | La seule surface de couplage entre les deux et le noyau. | `RouteRegistry`, `EntityRegistry`, … |

La règle qui tient l'ensemble : **un module ne touche jamais le cœur ; il ne fait que
remplir des registres.** C'est ce qui rend le système énumérable — et donc ce qui rendra le
module builder possible.

### Le cycle de boot

```
Kernel::boot()
  ├─ charge la configuration
  ├─ construit le container PSR-11
  ├─ chaque Contributor remplit les registres   ← libs d'abord, puis modules
  └─ RegistryCollection::freeze()               ← la forme du système est figée
```

Après `freeze()`, toute contribution lève une `FrozenRegistryException`. Aucun code de
requête ne peut modifier la forme du système : ce qui est énumérable au boot le reste.

`Contributor` est l'unique interface d'extension, et libs et modules l'implémentent à
l'identique :

```php
interface Contributor
{
    public function contribute(RegistryCollection $registries): void;
}
```

## Hooks et triggers

Distinction stricte, à ne jamais laisser dériver (implémentation en phase 2) :

| | **Hook** | **Trigger** |
|---|---|---|
| Moment | synchrone, dans le flux | après coup, après commit |
| Peut modifier ? | oui — la valeur circule de listener en listener | non |
| Exception | remonte (c'est de la logique métier) | capturée et loguée |
| Nommage | `invoice.total.compute` | `INVOICE_VALIDATED` |

## Démarrer

```bash
composer install
php -S localhost:8080 -t public
curl localhost:8080/            # {"status":"ok","routes":1}
php bin/liminal doctor
```

### Vérifications

```bash
composer lint     # PHP-CS-Fixer, PER-CS 2.0
composer stan     # PHPStan level max, sans baseline
composer test     # PHPUnit
```

Les tests d'intégration ont besoin d'un vrai serveur MariaDB — pas de SQLite, parce que le
comportement testé (filtres SQL, migrations, DDL réel) est exactement ce que SQLite
simulerait mal.

```bash
docker compose up -d db
export LIMINAL_TEST_DSN='mysql://liminal:liminal@127.0.0.1:3306/liminal_test'
composer test:integration
```

Sans `LIMINAL_TEST_DSN` joignable, la suite d'intégration se *skippe* au lieu d'échouer.

## Arborescence

```
bin/liminal          CLI
config/              app.php, database.php
libs/                capacités techniques (System, Database, …)
public/index.php     unique point d'entrée web
src/                 LE KERNEL — ni lib, ni module
  Config/ Console/ Container/ Http/ Registry/ Support/
tests/{Unit,Integration}
```

## État

| Phase | Contenu | État |
|---|---|---|
| 0 | Kernel, registres, pipeline PSR-15, CLI, CI | ✅ |
| 1 | `lib/database` : Doctrine, scoping multi-sociétés, migrations par module | ✅ |
| 2 → 8 | `lib/module`, `lib/security`, `lib/rendering`, `lib/api`, builder, modules | à venir |

## Multi-sociétés

Une entité qui implémente `EntityScoped` est automatiquement cloisonnée par société :
`EntityScopeFilter` ajoute `entity_id IN (...)` à chaque requête, et `prePersist` estampille
les nouvelles lignes.

**Le filtre SQL n'est pas une frontière de sécurité.** Doctrine ne l'applique qu'à la
génération du SQL : `find()` court-circuite sur l'identity map avant d'atteindre le
persister, donc avant le filtre. Deux mécanismes complémentaires ferment ce trou —
`EntityContext::switchTo()` vide l'EntityManager (rien d'hydraté sous l'ancienne portée ne
survit), et un garde `postLoad` refuse toute ligne étrangère même filtre désactivé. La
phase 3 ajoutera les voters par-dessus. Aucun des trois n'est suffisant seul.

Changer de société passe obligatoirement par `switchTo()` : il n'y a pas de setter simple,
précisément pour que l'éviction ne puisse pas être oubliée.
