# Format d'un modèle métier

Un modèle métier est un fichier JSON dans `templates/`, par exemple `templates/roofer.json` pour le couvreur. Il décrit les étapes, les questions, les conditions d'affichage, les textes d'aide et les messages envoyés au visiteur. Le code ne connaît aucun métier : tout passe par ce fichier.

L'extension ajoute elle-même deux étapes à la fin de chaque modèle : les coordonnées (nom, e-mail, téléphone), puis le récapitulatif avec le consentement RGPD. Un modèle ne les déclare pas et ne peut pas les oublier.

Les textes sont rédigés en anglais (langue source pour WordPress.org) et traduits au chargement. Après chaque modification d'un modèle livré avec l'extension, lancer `npm run i18n:templates` : la commande régénère `includes/template-strings.php`, une table qui associe chaque texte anglais à sa traduction. L'extension s'en sert pour traduire les modèles, et les outils de traduction y trouvent les textes. Aucune fonction de traduction ne reçoit de variable, ce que la relecture WordPress.org refuse.

## Exemple minimal

```json
{
  "schema": 1,
  "id": "roofer",
  "title": "Roofer",
  "description": "Roof repair and renovation.",
  "steps": [
    {
      "id": "service",
      "title": "Your project",
      "fields": [
        {
          "id": "service",
          "type": "choice",
          "label": "What do you need?",
          "required": true,
          "options": [
            { "value": "repair", "label": "Leak or roof repair" },
            { "value": "renovation", "label": "Roof renovation" }
          ]
        },
        {
          "id": "leak_now",
          "type": "yesno",
          "label": "Is water coming in right now?",
          "show_if": { "field": "service", "equals": "repair" }
        }
      ]
    }
  ],
  "messages": {
    "success": "Thank you {name}, your request has been sent.",
    "acknowledgement_subject": "Your quote request to {site_name}",
    "acknowledgement_body": "Hello {name},\n\nThank you for your request."
  }
}
```

## Racine du fichier

| Clé | Obligatoire | Rôle |
| --- | --- | --- |
| `schema` | oui | Version du format. Toujours `1` pour l'instant. |
| `id` | oui | Identifiant du modèle, en minuscules, chiffres, tirets ou soulignés. Il est enregistré sur chaque formulaire : ne plus le changer une fois publié. |
| `title` | oui | Nom du métier, affiché dans le choix du modèle et dans l'objet de la notification. |
| `description` | non | Une phrase sur ce que couvre le modèle, affichée dans le choix du métier. |
| `category` | non | Groupe dans le choix du métier : `building` (bâtiment), `outdoor` (extérieur et terrassement), `home` (services à domicile), `professional` (professions libérales et conseil), `general` (modèles généraux). Sans catégorie connue, le modèle va dans "Autres". |
| `keywords` | non | Mots-clés de recherche séparés par des virgules (synonymes, prestations, termes courants), traduits comme les autres textes. La recherche compare le début de chaque mot, sans tenir compte des accents ni des majuscules. |
| `steps` | oui | Liste des étapes, dans l'ordre. |
| `messages` | non | Textes envoyés au visiteur (voir plus bas). Un message absent prend le texte par défaut. |
| `single` | non | `true` pour un formulaire sur un seul écran, comme le modèle "Message simple" (`message.json`). Le modèle n'a alors qu'une étape : le nom, le téléphone et l'e-mail viennent en tête, la case de consentement à la fin, sans barre de progression ni étape de relecture. |

## Étapes

| Clé | Obligatoire | Rôle |
| --- | --- | --- |
| `id` | oui | Identifiant unique de l'étape (minuscules, chiffres, soulignés). `contact` et `review` sont réservés. |
| `title` | oui | Titre affiché en haut de l'étape et dans la barre de progression. |
| `description` | non | Texte d'introduction sous le titre. |
| `fields` | oui | Liste des questions de l'étape. |
| `show_if` | non | Condition d'affichage de toute l'étape. Une étape masquée est sautée, ses questions ne sont ni demandées ni enregistrées. |

Une étape dont toutes les questions sont masquées est sautée d'elle-même, sans condition `show_if` à écrire.

## Questions

Clés communes à tous les types :

| Clé | Obligatoire | Rôle |
| --- | --- | --- |
| `id` | oui | Identifiant unique dans tout le modèle (minuscules, chiffres, soulignés, commence par une lettre). `name`, `email`, `phone` et `consent` sont réservés. |
| `type` | oui | Un des types ci-dessous. |
| `label` | oui | La question. |
| `help` | non | Texte d'aide sous la question. |
| `placeholder` | non | Exemple affiché dans un champ vide (types `text`, `textarea`, `number`). |
| `required` | non | `true` pour une réponse obligatoire. Une question masquée n'est jamais obligatoire. |
| `show_if` | non | Condition d'affichage. |

Types et clés propres :

| Type | Affichage | Clés propres | Réponse enregistrée |
| --- | --- | --- | --- |
| `text` | Champ d'une ligne | `maxlength` (200 par défaut) | Texte |
| `textarea` | Zone de texte | `maxlength` (5 000 par défaut) | Texte |
| `number` | Champ numérique (virgule acceptée) | `min`, `max`, `unit` (par exemple `"m²"`) | Nombre, en texte |
| `choice` | Cartes cliquables | `options` (obligatoire), `multiple` (`true` pour plusieurs réponses) | Valeur de l'option, ou liste de valeurs |
| `select` | Liste déroulante | `options` (obligatoire) | Valeur de l'option |
| `yesno` | Deux cartes Oui et Non | | `yes` ou `no` |
| `date` | Sélecteur de date | | `AAAA-MM-JJ` |
| `address` | Rue, code postal, ville | | Objet `street`, `postcode`, `city` |
| `photos` | Bouton d'ajout et vignettes | `max` (6 par défaut, 10 au plus) | Nombre de photos, fichiers à part |

Une option de `choice` ou `select` s'écrit `{ "value": "slate", "label": "Natural slate" }`, avec un `help` facultatif. La valeur est ce qui est enregistré et testé par les conditions. Elle ne doit pas changer une fois le modèle publié, le libellé peut changer.

Pour une adresse obligatoire, les trois parties sont exigées. La ville de la première adresse sert au titre de la demande dans l'administration ("Jean Dupont, Vannes").

Les photos sont redimensionnées sur le téléphone (2 000 pixels de côté au plus, en JPEG) avant l'envoi.

## Conditions

`show_if` porte sur une question placée plus haut dans le modèle (jamais plus bas, ce que le chargement vérifie). Une condition contient un seul opérateur :

| Opérateur | Exemple | Vrai quand |
| --- | --- | --- |
| `equals` | `{ "field": "service", "equals": "repair" }` | La réponse vaut cette valeur, ou la contient pour un choix multiple. |
| `in` | `{ "field": "service", "in": ["repair", "renovation"] }` | La réponse est une de ces valeurs. |
| `not_in` | `{ "field": "service", "not_in": ["chimney"] }` | La réponse n'est aucune de ces valeurs. Aussi vrai quand la question est sans réponse. |
| `filled` | `{ "field": "roof_area", "filled": true }` | La question a une réponse (`true`) ou n'en a pas (`false`). |

Les conditions se combinent avec `all` (toutes vraies) et `any` (au moins une vraie), imbriquables :

```json
"show_if": {
  "all": [
    { "field": "service", "equals": "renovation" },
    { "field": "renovation_scope", "in": ["full", "new"] }
  ]
}
```

Une question masquée compte comme sans réponse pour les conditions qui suivent. Les masquages se propagent donc en cascade : si l'artisan retire une option, les questions qui en dépendent disparaissent aussi.

Le navigateur applique les conditions pendant la saisie (assets/form.js), et le serveur les évalue de nouveau à l'envoi (includes/class-conditions.php) : une réponse à une question masquée est ignorée, même si un navigateur l'envoie.

## Messages

Trois textes, avec des emplacements remplacés à l'envoi : `{name}`, `{email}`, `{site_name}` et `{reference}` (numéro de la demande).

| Clé | Rôle |
| --- | --- |
| `success` | Message affiché au visiteur à la place du formulaire, une fois la demande envoyée. |
| `acknowledgement_subject` | Objet de l'accusé de réception envoyé au visiteur. |
| `acknowledgement_body` | Début de l'accusé de réception. Les paragraphes sont séparés par une ligne vide (`\n\n`). Le récapitulatif des réponses est ajouté à la suite. |

## Adaptation par l'artisan

Sur l'écran d'un formulaire, la boîte "Questions et messages" permet à l'artisan de masquer une étape, une question ou une réponse, de renommer un titre d'étape, une question, un texte d'aide ou une réponse, et de réécrire les trois messages au visiteur. Il peut aussi ajouter ses propres questions (texte court ou long, nombre, choix, oui ou non, date, 30 au plus) et changer l'ordre des questions à l'intérieur d'une étape. Les étapes intégrées (coordonnées, récapitulatif et consentement) ne sont pas modifiables.

Une question ajoutée garde un identifiant `custom_` suivi de caractères aléatoires, et ses textes ne sont pas traduits (l'artisan les écrit dans sa langue). Un ordre qui placerait une question avant celle que lit sa condition est refusé par l'écran et ignoré par le serveur.

Seuls les écarts avec le modèle sont enregistrés (méta `_primiko_customizations` du formulaire, rangée par identifiant de modèle, voir includes/class-customizations.php). Conséquences :

- une amélioration du modèle dans une mise à jour de l'extension arrive aussi sur les formulaires adaptés, sans effacer les choix de l'artisan ;
- un écart qui vise une étape, une question ou une valeur que le modèle n'a plus est ignoré ;
- d'où l'importance de ne jamais changer l'identifiant d'une question ou la valeur d'une réponse une fois le modèle publié (les libellés, eux, peuvent changer).

Une étape, une question ou une réponse masquée est retirée du schéma : elle n'est ni affichée, ni acceptée à l'envoi. Une question dont toutes les réponses sont masquées disparaît, une étape sans question aussi. Les questions qui dépendent d'une réponse masquée ne s'affichent plus (une question masquée compte comme sans réponse), et l'écran d'édition le signale.

## Modèles hors de l'extension

Un thème ou une autre extension peut ajouter un dossier de modèles avec le filtre `primiko_template_dirs`. Un modèle avec le même `id` qu'un modèle livré le remplace. Ses textes se traduisent avec le filtre `primiko_template_text` (texte traduit, texte anglais, identifiant du modèle).

```php
add_filter( 'primiko_template_dirs', function ( $dirs ) {
	$dirs[] = get_stylesheet_directory() . '/primiko';
	return $dirs;
} );
```

Le filtre `primiko_form_schema` reçoit le schéma complet d'un formulaire (modèle traduit et étapes intégrées) : le même schéma sert à l'affichage et à la validation.

## Vérifications au chargement

Un fichier invalide est ignoré, avec un message pour les développeurs quand `WP_DEBUG` est actif (identifiant en double ou réservé, type inconnu, option sans valeur, condition sur une question placée plus bas, opérateur absent ou en double). Les autres modèles restent disponibles.
