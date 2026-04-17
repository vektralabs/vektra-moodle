# Moodle 4.3.3 test environment

Setup isolato per testare il plugin `block_vektra` su **Moodle 4.3.3** senza
modificare i file della versione ufficiale (5.1).

## Perché questa cartella

Il plugin è dichiarato ufficialmente per Moodle 5.1 (`version.php` della root,
`supported=[501, 501]`). Moodle 4.3 rifiuterebbe l'installazione senza un
range di compatibilità più ampio. Invece di modificare il file ufficiale,
questa cartella contiene un **override** che viene montato al posto del
`version.php` originale tramite bind-mount del `docker-compose.yml`.

## File in questa cartella

| File | Ruolo |
|---|---|
| `Dockerfile` | costruisce immagine con PHP 8.1 + Moodle 4.3.3 |
| `docker-compose.yml` | orchestra container Moodle + MariaDB, doppio bind mount del plugin |
| `docker-entrypoint.sh` | script di primo avvio (installa Moodle, crea admin) |
| `php.ini` | tuning PHP (memory_limit, upload size, max_input_vars) |
| `version.php` | **override** del file omonimo della root (`supported=[403, 501]`) |
| `.env` | variabili di configurazione (porte, credenziali, ecc.) |

Il file `version.php` di questa cartella è l'unica cosa che rende Moodle 4.3
accettabile. Viene montato come singolo file sopra il plugin (vedi `volumes:` nel compose).

## Come si usa

### Avviare l'ambiente 4.3.3

```bash
cd vektra-moodle/docker-moodle43
cp .env.example .env   # se .env non esiste
docker compose up -d --build
```

Aspettare ~5 min (build image + install Moodle). Poi:

- Moodle: http://localhost:8080 (user/pass da `.env`)
- MariaDB: 127.0.0.1:10406 (solo diagnostica)

### Collegare Vektra (stack esterno)

Il plugin richiede che `vektra-stack` giri separatamente. Dopo l'avvio:

```bash
# collega la rete Docker di vektra-stack
docker network connect vektra-stack_default vektra-moodle43
# verifica
docker exec vektra-moodle43 curl -sf http://vektra-stack-vektra-1:8000/health
```

Configurare il plugin nelle impostazioni di Moodle (Site administration > Plugins
> Blocks > Vektra AI Assistant) con:

- **Vektra API URL**: `http://vektra-stack-vektra-1:8000`
- **Public URL**: `http://localhost:8000`
- **API Key**: la admin key del tuo `vektra-stack`

### Avviare anche Moodle 5.1 in parallelo

I due ambienti convivono senza conflitti (nomi container, volumi e porte
distinti). Da un'altra shell:

```bash
cd vektra-moodle/docker
docker compose up -d --build
# Moodle 5.1 su http://localhost:10180
```

### Spegnere tutto

```bash
docker compose down       # mantiene i dati
docker compose down -v    # cancella anche i volumi (reset completo)
```

## Dual-host Moodle (dev locale)

Il container usa un `config.php` con `wwwroot` dinamico basato su
`$_SERVER['HTTP_HOST']`, così sia il browser (`localhost:8080`) sia i container
(`http://vektra-moodle43`) vedono le URL coerenti nella stessa installazione.
Questo tweak **non è necessario** in produzione con reverse proxy configurato
correttamente (`$CFG->reverseproxy = true`).

## Note tecniche

- PHP 8.1 richiesto (Moodle 4.3 non supporta 8.3)
- Moodle 4.3 serve direttamente da `/var/www/html` (nessun split `public/`
  come in Moodle 5.1)
- Estensione PHP `exif` aggiunta al Dockerfile (era mancante nel Dockerfile 5.1)
- Porta default 8080 (vs 10180 della 5.1) per evitare collisioni

## Quando questa cartella non serve più

Se in futuro il plugin verrà dichiarato ufficialmente compatibile con Moodle 4.3
(cioè il `version.php` della root aggiornato a `supported=[403, 501]`), questa
intera cartella potrà essere cancellata: il setup Moodle 4.3 si potrebbe
mantenere nella struttura standard `docker/` parametrizzando la build arg
`MOODLE_TGZ_URL`.
