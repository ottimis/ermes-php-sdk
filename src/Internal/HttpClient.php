<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Internal;

use Ottimis\Ermes\Http\CurlHttpClient;

/**
 * @deprecated dalla 1.2.0. Usare Ottimis\Ermes\Http\CurlHttpClient, che ha la stessa API.
 *             Questa classe resta solo per non rompere eventuali riferimenti diretti e
 *             sarà rimossa nella 2.0.
 */
class HttpClient extends CurlHttpClient
{
}
