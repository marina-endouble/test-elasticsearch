<?php
use Faker\Factory;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require_once ('vendor/autoload.php');

$logger = new Logger('log');
$date = (new DateTime('now'))->format("y-m-d-h-i-s");

$logger->pushHandler(new StreamHandler('hit-elasticsearch-' . $date . '.log'));

$elasticClient = new Client(
    [
        'base_uri' => 'https://f2d93f151cde4f5e8d39dbc7efb6157c.eu-west-1.aws.found.io:9243',
    ]
);
$ritualsClient = new Client(
    [
        'base_uri' => 'https://dutch-netherlands-csb-site-rituals.stage.endouble.net',
    ]
);

$authToken = 'Basic ZWxhc3RpYzowanNYcnlKdmdxd2dDR2Z2ZVo4ZHlTRnM=';

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


$statsCallback = function (TransferStats $stats) use ($logger) {
    $time = $stats->getTransferTime();
    if ($time < 1 ) {
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

$requestToElastic = new Request(
    'POST',
    'csb_site_rituals_stage_prod_2017-07-05-121300/vacancy/_search?size=10',
    [
        'Content-Type' => 'application/json',
        'Authorization' => $authToken,
        'Cache-Control' => 'no-cache',
    ],
    $query
);

$requestToRituals = new Request(
    'GET',
    'api/vacancies?search=&filter[companies][]=Head%20office&filter[companies][]=Central%20head%20office&filter[cities][]=Amsterdam&filter[location_names][]=Amsterdam%20Beethovenstraat&page=1&limit=12',
    [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-cache',
    ]
);

do {
    try {
        $start = (float)microtime(true);
        /** @var Response $response */
        $response = $elasticClient->send(
            $requestToElastic,
//            ['on_stats' => $statsCallback, 'curl' => [CURLOPT_FRESH_CONNECT => 1],]
            ['on_stats' => $statsCallback,]
        );
//        $response = $ritualsClient->send($requestToRituals, ['on_stats' => $statsCallback]);
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

    usleep(100);
} while (1);
