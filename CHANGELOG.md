# Changelog

Tutte le modifiche rilevanti di `ottimis/ermes-php-sdk`.
Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il versionamento è [SemVer](https://semver.org/lang/it/).

## [1.3.4] - 2026-09-16

Versione che porta dentro l'SDK cose che ogni consumatore era costretto a riscrivere. Tutte le
aggiunte sono additive: nessuna firma esistente cambia. Un solo comportamento cambia davvero,
ed è segnalato sotto *Changed*.

### Fixed
- **La 1.3.2 non si carica su PHP 8.1 e 8.2.** Due costanti usavano la sintassi tipizzata
  (`private const array`), disponibile solo da PHP 8.3, mentre il pacchetto dichiara `^8.1`:
  su quelle versioni era un errore di parsing, non un errore a runtime. Tipo rimosso.

### Added
- **`Jwks`**, classe autonoma: `Jwks::fromPrivateKey()` e `Jwks::publicKeysFromDirectory()`.
  Un tenant deve pubblicare il proprio JWKS **prima** di essere registrato su Ermes, quando
  `apiKey`/`apiSecret` non esistono ancora — e dalla 1.3.0 il costruttore di
  `NotificationConfig` solleva proprio in quel caso. Derivare un JWK è un'operazione locale e
  ora non richiede più un client: chi pubblica il JWKS non deve più scegliere fra costruire
  una configurazione finta e riscriversi la derivazione a mano.
  `publicKeysFromDirectory()` copre la rotazione: `additionalPublicKeys` accettava solo PEM
  già in memoria, quindi il caricamento se lo scriveva ognuno.
- **`deleteNotification()`, `deleteBulk()`, `restoreNotification()`**: esistevano sul core e
  non nell'SDK, quindi venivano chiamati con cURL scritto a mano, duplicando il client HTTP e
  rifirmando un token RSA a ogni richiesta invece di usare la cache.
- **`verifyUserToken()`**: l'SDK emetteva token e non ne verificava nessuno, ma la libreria
  Angular usa **un solo token** sia per Socket.IO sia verso il backend del tenant, che quindi
  deve saperlo riverificare. Verifica contro la chiave attiva **e** contro
  `additionalPublicKeys`: durante una rotazione i token firmati con la chiave uscente sono
  ancora nel JWKS, quindi il core li accetta, e rifiutarli qui rendeva la rotazione indolore
  solo a metà.
- **`extraClaims`** su `createUserToken()` e `createUserTokenWithInfo()`, per i claim
  applicativi — l'id di sessione con cui si revoca un token al logout, per esempio. I claim
  riservati (`iss`, `aud`, `sub`, `exp`, `iat`, `tenant_id`, `roles`) non sono
  sovrascrivibili: un tentativo solleva.
- **`planLiveBatches()`**: prepara i lotti che il core accetta. L'SDK conosceva i due limiti —
  500 destinatari per evento, 100 eventi per chiamata — e si limitava a sollevare quando li si
  superava, lasciando al chiamante l'unica parte non ovvia. L'ordine è quello che conta: prima
  si spezzano i destinatari, che aumenta il numero di eventi, poi si raggruppa.
- **`published`, `failed` e `partial`** nel risultato delle rotte live.
- **PEM in base64** accettato da `NOTIFICATION_RSA_PRIVATE_KEY`, oltre al PEM multilinea e a
  quello con `\n` letterali: ogni sistema di secret ne impone una forma diversa.
- **Validazione del limite di 200 UUID** su `markBulkRead()` e `deleteBulk()`, che il README
  prometteva senza che nessuno la verificasse.

### Changed
- **Un `202` con `failed > 0` non è più un successo.** Il core risponde 202 quando ha
  *accettato* il lotto, non quando l'ha pubblicato tutto: `202 {published: 0, failed: 100}`
  significa che non è arrivato niente, e finora tornava come `success: true`. Ora `success`
  tiene conto di `failed`. Se dipendevi dal comportamento precedente, guarda `partial` e
  `failed`, che ci sono entrambi. `skipped_offline` non concorre: nessun destinatario
  collegato è il funzionamento previsto di un evento live, non un fallimento.

## [1.3.2] - 2026-09-16

### Fixed
- `getNotifications()` e `syncNotifications()` inoltravano al core solo una parte dei filtri:
  la whitelist interna era più stretta del contratto del core, e i parametri fuori elenco
  sparivano **senza alcun errore**. Il caso concreto: con `deleted` scartato, l'archivio delle
  notifiche risultava vuoto pur essendo popolato. Ora passano tutti i filtri che il core
  dichiara — per la lista `status`, `topic`, `application_id`, `created_after`,
  `created_before`, `deleted`, `page`, `limit`; per il sync `after`, `limit`, `created_after`,
  `created_before`, `deleted` — e continuano a essere scartati quelli che il core non conosce.

## [1.3.1] - 2026-09-16

### Added
- `audience` configurabile: sul core l'audience è una colonna del tenant, quindi un valore
  fisso rendeva l'SDK inutilizzabile su un tenant provisionato diversamente.

### Fixed
- `sendEvent()` costruiva il payload unendo i campi del chiamante **dopo** quelli autorevoli:
  un evento che contenesse `tenant_key` o `application_id` li sovrascriveva e partiva
  dichiarando un mittente diverso da quello autenticato dalle credenziali. Ora quei due campi
  vengono sempre dalla configurazione; `event_id` resta del chiamante, perché è la chiave di
  idempotenza e serve ai retry.

## [1.2.0] - 2026-09-16

Nessuna firma pubblica esistente è cambiata: chi è su `^1.1` aggiorna senza toccare il proprio codice.
I due cambi di comportamento da conoscere prima di aggiornare sono la durata dei token e i timeout, entrambi in **Changed**.

### Security

- **I token utente durano un'ora, non più nove anni.** `createUserToken()` e `createUserTokenWithInfo()`
  accettano un `$ttl` opzionale e, senza, usano `userTokenTtl` della configurazione (default 3600 s).
  Un token RS256 federato via JWKS non è revocabile: finché scade in un'ora, un token rubato vale un'ora.
  Chi ha davvero bisogno di un token lungo ora lo chiede esplicitamente.
- **I segreti non finiscono più nei dump.** `apiSecret` e `privateKeyPem` non sono più proprietà pubbliche:
  stanno in due closure e si leggono con `apiSecret()` e `privateKeyPem()`. `var_dump`, `print_r`,
  `var_export` e `json_encode` della configurazione non li mostrano. I parametri sensibili del costruttore
  e di `createUserToken*()` sono marcati `#[\SensitiveParameter]`, così restano fuori anche dagli stack trace
  (PHP 8.2+).
- **Rotazione delle chiavi senza downtime**: `additionalPublicKeys` (`kid => PEM`) pubblica altre chiavi
  nella JWKS accanto a quella corrente, così i token già emessi restano validabili mentre si firma con la nuova.

### Added

- `NotificationConfig::$enabled` (env `NOTIFICATION_ENABLED`, oppure il kill switch `ERMES_DISABLED`):
  con Ermes spento nessuna chiamata parte e nessuna paga il timeout. I metodi di rete tornano
  `['success' => true, 'skipped' => true, ...]`.
- Tutti i risultati contengono ora `error` (il messaggio cURL quando la richiesta non è arrivata a
  destinazione), `skipped`, e sia `core_status` sia `statusCode` — stesso valore, per non dover cambiare
  i chiamanti esistenti.
- `sendEvent()` restituisce l'`event_id` effettivamente usato, così un retry può riusare la stessa chiave
  di idempotenza.
- Validazione locale dei vincoli del core, con `InvalidArgumentException` che nomina il campo invece di una
  400 `invalid_payload` da interpretare: `topic` e `title` obbligatori, `recipient_users` fra 1 e 500,
  `severity` nell'enum, lunghezze massime, e per i live event `event_name` sul pattern e diverso da
  `notification.new`, batch fra 1 e 100 eventi.
- `Ottimis\Ermes\Http\HttpClientInterface` iniettabile nel costruttore di `NotificationClient`:
  i consumatori possono testare senza rete o passare per il proprio trasporto.
- Logger PSR-3 opzionale, terzo argomento di `NotificationClient`, usato per segnalare le chiavi che la
  JWKS non riesce a pubblicare.
- `POST /api/v1/events/live` (`sendLiveEvents()`, `sendLiveEvent()`) e `GET /api/v1/presence`
  (`getPresence()`) per il core Ermes 0.2.0: eventi dati non persistiti, recapitati ai soli destinatari
  online, e lettura di chi è collegato.
- Timeout per chiamata (`timeout_ms`, `connect_timeout_ms`) su tutti i metodi di rete, non più solo su
  quelli live.
- Test (PHPUnit), analisi statica (PHPStan livello 6), CI su PHP 8.1-8.4, `LICENSE`, `.gitignore`,
  questo changelog, e `openapi.yaml` aggiornato al core 0.2.0.

### Changed

- **Timeout molto più corti**: 2 s per la risposta e 1 s per la connessione, al posto degli
  unici 10 s di prima e di nessun limite sulla connessione. Un core irraggiungibile non blocca più la
  richiesta del consumatore per dieci secondi. Chi fa invii in batch fuori dal ciclo di richiesta alza i
  valori con `timeoutMs` / `connectTimeoutMs` nella configurazione, o per singola chiamata.
- `sendEvent()` considera un successo anche il **200 `already_processed`** che il core restituisce sul
  replay di un `event_id` già visto. Prima un retry corretto veniva letto come fallimento e ritentato ancora.
- `NotificationConfig::fromEnv()` fallisce subito nominando la variabile mancante, e verifica che
  `NOTIFICATION_CORE_URL` sia un URL http(s) assoluto. Prima una variabile vuota diventava una 401 o un
  errore cURL muto.
- `getJwks()` non lancia più: una chiave illeggibile o non RSA torna `['keys' => []]` e viene loggata,
  invece di far rispondere 500 a `/.well-known/jwks.json` e togliere a Ermes la possibilità di validare i
  token del tenant.
- Il corpo JSON è serializzato con `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE |
  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`: un testo non UTF-8 non produce più un POST con corpo
  vuoto e una 400 indecifrabile.
- `getNotifications()` e `syncNotifications()` inoltrano al core solo i parametri che conosce
  (`status`, `page`, `limit`, `topic`; `after`, `limit`). Gli altri vengono ignorati: questi metodi
  ricevono spesso una query string grezza.
- Lo stesso utente riusa il proprio token finché è valido, invece di pagare una firma RSA a ogni chiamata.
- `declare(strict_types=1)` in tutti i file; `ext-curl`, `ext-json`, `ext-mbstring` ed `ext-openssl`
  dichiarate in `require`; tolto il campo `version` da `composer.json` (fa fede il tag git).

### Deprecated

- `Ottimis\Ermes\Internal\HttpClient` è ora una sottoclasse vuota di `Ottimis\Ermes\Http\CurlHttpClient`
  e sarà rimossa nella 2.0.

### Note per chi aggiorna

- `event_id` continua a essere generato quando non lo passi, ma resta **casuale**: ogni retry applicativo
  crea una notifica duplicata. Per un invio ritentabile passa un identificatore deterministico del fatto di
  dominio. Renderlo obbligatorio è un candidato per la 2.0.
- Se leggevi `$config->apiSecret` o `$config->privateKeyPem` come proprietà, usa i metodi omonimi.
- Il vincolo su `firebase/php-jwt` resta `^6.0 || ^7.0` per non rompere chi è ancora sulla 6, ma ogni
  versione sotto la 7.0.0 è coperta da [CVE-2025-45769](https://github.com/advisories/GHSA-2x45-7fc3-mxwq)
  e Composer la blocca in fase di audit: conviene salire a `^7.0` con questo aggiornamento.

## [1.1.0]

- Supporto a `firebase/php-jwt` `^7.0` accanto a `^6.0`, che toglie il conflitto con
  `ottimis/phplibs:^8`.

## [1.0.0]

- Prima versione: invio eventi, token utente, JWKS, proxy dell'inbox.
