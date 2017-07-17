<?php
use Faker\Factory;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Yaml\Yaml;

require_once ('vendor/autoload.php');

$logger = new Logger('log');
$date = (new DateTime('now'))->format("y-m-d-h-i-s");

$logger->pushHandler(new StreamHandler('hit-elasticsearch-' . $date . '.log'));

$config = Yaml::parse(file_get_contents(__DIR__ . '/parameters.yml'));

$faker = Factory::create();
$query = <<< 'EOT'
{
  "query": {
    "bool": {
      "must": [
        {
          "bool": {
            "boost": 0,
            "must": [
              {
                "range": {
                  "start_date": {
                    "lte": "now"
                  }
                }
              },
              {
                "bool": {
                  "should": [
                    {
                      "range": {
                        "end_date": {
                          "gte": "now"
                        }
                      }
                    },
                    {
                      "bool": {
                        "must_not": [
                          {
                            "exists": {
                              "field": "end_date"
                            }
                          }
                        ]
                      }
                    }
                  ]
                }
              },
              {
                "term": {
                  "is_closed": {
                    "value": false,
                    "boost": 1
                  }
                }
              }
            ]
          }
        },
        {
          "term": {
            "locale": {
              "value": "en_NL",
              "boost": 0
            }
          }
        }
      ]
    }
  },
  "sort": [
    {
      "start_date": {
        "order": "DESC"
      }
    },
    {
      "start_date": {
        "order": "DESC"
      }
    }
  ],
  "aggs": {
    "groupings": {
      "nested": {
        "path": "groupings"
      },
      "aggs": {
        "name": {
          "terms": {
            "field": "groupings.name.keyword",
            "min_doc_count": 0,
            "size": 1000
          },
          "aggs": {
            "value": {
              "terms": {
                "field": "groupings.value.keyword",
                "size": 100
              }
            }
          }
        }
      }
    }
  }
}
EOT;

$statsCallback = function (TransferStats $stats) use ($logger, $config) {
    $time = $stats->getTransferTime();
    if ($time < $config['critical_time_interval'] ) {
        return;
    }
    $logger->warn(
        $stats->getTransferTime(),
        [
            'handler stats' => $stats->getHandlerStats(),
            'handler error data' => $stats->getHandlerErrorData(),
            'response' => $stats->getResponse(),
        ]
    );
};

$elasticRequest = new Request(
    'POST',
    $config['elastic_uri'],
    [
        'Content-Type' => 'application/json',
        'Authorization' => $config['elastic_token'],
        'Cache-Control' => 'no-cache',
    ],
    $query
);

$csbRequest = new Request(
    'GET',
    $config['csb_site_uri'],
    [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-cache',
    ]
);

$elasticClient = new Client(
    [
        'base_uri' => $config['elastic_base_url'],
    ]
);

$csbClient = new Client(
    [
        'base_uri' => $config['csb_site_base_url'],
    ]
);

$request = ${"{$config['site']}Request"};
$client = ${"{$config['site']}Client"};

do {
    try {
        $start = (float)microtime(true);
        /** @var Response $response */
        $response = $client->send(
            $request,
            [
                'on_stats' => $statsCallback,
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]
        );
        $time = (float)microtime(true) - $start;
    } catch (Exception $e) {
        $time = (float)microtime(true) - $start;
        $logger->addError(
            'Status: ' . $e->getCode() . ', message: ' . $e->getMessage() . ', took: ' . $time,
            [
                'exception' => $e,
            ]
        );
        continue;
    }

    usleep(10);
} while (1);
