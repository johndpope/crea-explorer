<?php
namespace CrearyDB\Controllers;

use \Datetime;

use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

use CrearyDB\Models\Account;
use CrearyDB\Models\AccountHistory;
use CrearyDB\Models\AuthorReward;
use CrearyDB\Models\BenefactorReward;
use CrearyDB\Models\Block30d;
use CrearyDB\Models\Comment;
use CrearyDB\Models\CurationReward;
use CrearyDB\Models\Follow;
use CrearyDB\Models\Reblog;
use CrearyDB\Models\Vote;
use CrearyDB\Models\Statistics;
use CrearyDB\Models\Pow;
use CrearyDB\Models\Transfer;
use CrearyDB\Models\VestingDeposit;
use CrearyDB\Models\VestingWithdraw;
use CrearyDB\Models\WitnessMiss;
use CrearyDB\Models\WitnessHistory;
use CrearyDB\Models\WitnessVote;

class AccountController extends ControllerBase
{

  private function getAccount()
  {
    $account = strtolower($this->dispatcher->getParam("account"));
    $cacheKey = 'account-'.$account;
    // Load account from the database
    $this->view->account = Account::findFirst(array(
      array(
        'name' => $account
      )
    ));
    // Check the cache for this account from the blockchain
    $cached = $this->memcached->get($cacheKey);
    // No cache, let's load
    if($cached === null) {
      $this->view->live = $this->Cread->getAccount($account);
      $this->memcached->save($cacheKey, $this->view->live, 60);
    } else {
      // Use cache
      $this->view->live = $cached;
    }
    // Calculate actual voting power
    $current_time = new DateTime();
    $last_vote_power = $this->view->live[0]['voting_power'];
    $last_vote_time = new DateTime($this->view->live[0]['last_vote_time']);
    $secondsago = $current_time->getTimestamp() - $last_vote_time->getTimestamp();
    $calculated_power = $last_vote_power + (10000 * $secondsago / 432000);
    $this->view->actual_voting_power = min(round($calculated_power / 10000 * 100, 2), 100);
    return $account;
  }

  public function viewAction()
  {
    $account = $this->getAccount();
    $this->view->props = $this->Cread->getProps();
    try {
      $this->view->activity = array_reverse($this->Cread->getAccountHistory($account));
    } catch (Exception $e) {
      $this->view->activity = false;
    }
    $this->view->mining = Pow::find(array(
      array(
        'witness' => $account,
      ),
      'sort' => array('_ts' => -1),
      'limit' => 100
    ));
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function propsAction()
  {
    $account = $this->getAccount();
    $this->view->history = WitnessHistory::find(array(
      ['owner' => $account],
      'sort' => array('created' => -1),
      'limit' => 100
    ));
    $this->view->pick("account/view");
  }

  public function postsAction()
  {
    $account = $this->getAccount();
    $this->view->comments = Comment::find(array(
      array(
        'author' => $account,
        'depth' => 0,
      ),
      'sort' => array('created' => -1),
      'limit' => 100
    ));
    $this->view->total_payouts = 0;
    $this->view->total_pending = 0;
    foreach($this->view->comments as $comment) {
      if($comment->pending_payout_value) {
        $this->view->total_pending += $comment->pending_payout_value;
      }
      if($comment->total_payout_value) {
        $this->view->total_payouts += $comment->total_payout_value;
      }
    }
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function votesAction()
  {
    $account = $this->getAccount();
    $this->view->filter = $this->request->get('type');
    switch($this->view->filter) {
      case "incoming":
        $query = array(
          'author' => $account,
        );
        break;
      default:
        $query = array(
          'voter' => $account,
        );
        break;
    }
    $this->view->votes = Vote::find(array(
      $query,
      'sort' => array('_ts' => -1),
      'limit' => 200
    ));
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function repliesAction()
  {
    $account = $this->getAccount();
    $this->view->replies = Comment::find(array(
      array(
        'author' => $account,
        'depth' => ['$gt' => 0],
      ),
      'sort' => array('created' => -1),
      'limit' => 100
    ));
    $this->view->pick("account/view");
  }

  public function followersAction()
  {
    $account = $this->getAccount();
    $this->view->followers = Follow::find([
      ["following" => $account],
      "sort" => ['_ts' => -1]
    ]);
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function followersWhalesAction()
  {
    $account = $this->getAccount();
    $followers = $this->view->account->followers;
    $this->view->followers = Account::find([
      ['name' => ['$in' => $followers]],
      'sort' => ['vesting_shares' => -1],
    ]);
    $this->view->pick("account/view");
  }

  public function followingAction()
  {
    $account = $this->getAccount();
    $this->view->followers = Follow::find([
      ["follower" => $account],
      "sort" => ['_ts' => -1]
    ]);
    $this->view->pick("account/view");
  }

  public function witnessAction()
  {
    $account = $this->getAccount();
    $this->view->votes = WitnessVote::agg([
      ['$match' => [
        'witness' => $account
      ]],
      ['$sort' => [
        '_ts' => -1
      ]],
      ['$limit' => 100],
      ['$lookup' => [
        'from' => 'account',
        'localField' => 'account',
        'foreignField' => 'name',
        'as' => 'voter'
      ]],
    ]);
    $this->view->witnessing = Account::agg([
      ['$match' => [
          'witness_votes' => $account,
      ]],
      ['$project' => [
        'name' => '$name',
        'weight' => ['$sum' => ['$vesting_shares', '$proxy_witness']]
      ]],
      ['$sort' => ['weight' => -1]]
    ])->toArray();
    $this->view->witness_votes = array_sum(array_map(function($item) {
      return $item['weight'];
    }, $this->view->witnessing));
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function blocksAction()
  {
    $account = $this->getAccount();
    $query = array(
      array(
        'witness' => $account,
      ),
      'sort' => array('_ts' => -1),
      'limit' => 100
    );
    // var_dump($query); exit;
    $this->view->mining = Block30d::find($query);
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function missedAction()
  {
    $account = $this->getAccount();
    $this->view->mining = WitnessMiss::find(array(
      array(
        'witness' => $account,
      ),
      'sort' => array('date' => -1),
      'limit' => 100
    ));
    $this->view->pick("account/view");
  }

  public function reblogsAction()
  {
    $account = $this->getAccount();
    $page = $this->view->page = (int) $this->request->get('page') ?: 1;
    $this->view->reblogs = Reblog::find(array(
      array(
        'account' => $account,
      ),
      'sort' => array('_ts' => -1),
      'limit' => 100,
      'skip' => 100 * ($page - 1)
    ));
    $this->view->pick("account/view");
  }

  public function rebloggedAction()
  {
    $account = $this->getAccount();
    $page = $this->view->page = (int) $this->request->get('page') ?: 1;
    $this->view->reblogs = Reblog::find(array(
      array(
        'author' => $account,
      ),
      'sort' => array('_ts' => -1),
      'limit' => 100,
      'skip' => 100 * ($page - 1)
    ));
    $this->view->pick("account/view");
  }

  public function proxiedAction()
  {
    $account = $this->getAccount();
    $this->view->proxied = Account::find(array(
      array('proxy' => $account)
    ));
    $this->view->pick("account/view");
  }

  public function curationAction()
  {
    $account = $this->getAccount();
    $this->view->curation = CurationReward::agg(array(
      ['$match' => [
        'curator' => $account,
        ]],
      ['$group' => [
        '_id' => [
          'doy' => ['$dayOfYear' => '$_ts'],
          'year' => ['$year' => '$_ts'],
          'month' => ['$month' => '$_ts'],
          'week' => ['$week' => '$_ts'],
          'day' => ['$dayOfMonth' => '$_ts']
        ],
        '_ts' => ['$first' => '$_ts'],
        'reward' => ['$sum' => '$reward'],
        'votes' => ['$sum' => 1],
        ]],
      ['$sort' => [
        '_ts' => -1,
        ]],
    ));
    $this->view->stats = CurationReward::agg([
      [
        '$match' => [
          'curator' => $account,
          '_ts' => [
            '$gte' => new UTCDateTime(strtotime("-30 days") * 1000),
          ],
        ]
      ],
      [
        '$group' => [
          '_id' => '$curator',
          'day' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-1 days") * 1000)
              ]
            ],
            '$reward',
            0
          ]]],
          'week' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-7 days") * 1000)
              ]
            ],
            '$reward',
            0
          ]]],
          'month' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-30 days") * 1000)
              ]
            ],
            '$reward',
            0
          ]]],
        ]
      ]
    ])->toArray();
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function curationDateAction() {
    $account = $this->getAccount();
    $this->view->date = $this->dispatcher->getParam("date");
    $this->view->curation = CurationReward::find(array(
      array(
        'curator' => $account,
        '_ts' => [
          '$gte' => new UTCDateTime(strtotime($this->view->date) * 1000),
          '$lte' => new UTCDateTime((strtotime($this->view->date) + 86400) * 1000),
        ]
      ),
      'sort' => array('_ts' => -1),
      'skip' => $limit * ($page - 1),
      'limit' => $limit,
    ));
    $this->view->pick("account/view");
  }

  public function beneficiariesAction()
  {
    $account = $this->getAccount();
    $this->view->beneficiaries = BenefactorReward::agg(array(
      ['$match' => [
        'benefactor' => $account,
        ]],
      ['$group' => [
        '_id' => [
          'doy' => ['$dayOfYear' => '$_ts'],
          'year' => ['$year' => '$_ts'],
          'month' => ['$month' => '$_ts'],
          'week' => ['$week' => '$_ts'],
          'day' => ['$dayOfMonth' => '$_ts']
        ],
        '_ts' => ['$first' => '$_ts'],
        'reward' => ['$sum' => '$reward'],
        'posts' => ['$sum' => 1],
        ]],
      ['$sort' => [
        '_ts' => -1,
        ]],
    ));
    $this->view->stats = BenefactorReward::agg([
      [
        '$match' => [
          'benefactor' => $account,
          '_ts' => [
            '$gte' => new UTCDateTime(strtotime("-30 days") * 1000),
          ],
        ]
      ],
      [
        '$group' => [
          '_id' => '$benefactor',
          'day' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-1 days") * 1000)
              ]
            ],
            '$reward',
            0
          ]]],
          'week' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-7 days") * 1000)
              ]
            ],
            '$reward',
            0
          ]]],
          'month' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-30 days") * 1000)
              ]
            ],
            '$reward',
            0
          ]]],
        ]
      ]
    ])->toArray();
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function beneficiariesDateAction() {
    $account = $this->getAccount();
    $this->view->date = $this->dispatcher->getParam("date");
    $this->view->beneficiaries = BenefactorReward::find(array(
      array(
        'benefactor' => $account,
        '_ts' => [
          '$gte' => new UTCDateTime(strtotime($this->view->date) * 1000),
          '$lte' => new UTCDateTime((strtotime($this->view->date) + 86400) * 1000),
        ]
      ),
      'sort' => array('_ts' => -1),
      'skip' => $limit * ($page - 1),
      'limit' => $limit,
    ));
    $this->view->pick("account/view");
  }


  public function authoringAction()
  {
    $account = $this->getAccount();
    $this->view->filter = $filter = $this->request->get('filter', 'string') ?: null;
    // Define our default match against rewards
    $match = [
      'author' => $account,
    ];
    switch($filter) {
      case "comments":
        $match['permlink'] = ['$regex' => new Regex('^re-', 'i')];
        break;
      case "posts":
        $match['permlink'] = ['$regex' => new Regex('^(?!re-)', 'i')];
        break;
    }
    $this->view->authoring = AuthorReward::agg(array(
      ['$match' => $match],
      ['$group' => [
        '_id' => [
          'doy' => ['$dayOfYear' => '$_ts'],
          'year' => ['$year' => '$_ts'],
          'month' => ['$month' => '$_ts'],
          'week' => ['$week' => '$_ts'],
          'day' => ['$dayOfMonth' => '$_ts']
        ],
        '_ts' => ['$first' => '$_ts'],
        'cbd_payout' => ['$sum' => '$cbd_payout'],
        'crea_payout' => ['$sum' => '$crea_payout'],
        'vesting_payout' => ['$sum' => '$vesting_payout'],
        'posts' => ['$sum' => 1],
      ]],
      ['$sort' => [
        '_ts' => -1,
      ]],
    ));
    $this->view->stats = AuthorReward::agg([
      [
        '$match' => [
          'author' => $account,
          '_ts' => [
            '$gte' => new UTCDateTime(strtotime("-30 days") * 1000),
          ],
        ]
      ],
      [
        '$group' => [
          '_id' => '$author',
          'day' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-1 days") * 1000)
              ]
            ],
            '$vesting_payout',
            0
          ]]],
          'week' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-7 days") * 1000)
              ]
            ],
            '$vesting_payout',
            0
          ]]],
          'month' => ['$sum' => ['$cond' => [
            [
              '$gte' => [
                '$_ts',
                new UTCDateTime(strtotime("-30 days") * 1000)
              ]
            ],
            '$vesting_payout',
            0
          ]]],
        ]
      ]
    ])->toArray();
    $this->view->chart = true;
    $this->view->pick("account/view");
  }


  public function authoringDateAction()
  {
    $account = $this->getAccount();
    $this->view->page = $page = (int) $this->request->get("page") ?: 1;
    $this->view->date = $this->dispatcher->getParam("date");
    $limit = 50;
    $this->view->filter = $filter = $this->request->get('filter', 'string') ?: null;
    // Define our default match against rewards
    $match = [
      'author' => $account,
      '_ts' => [
        '$gte' => new UTCDateTime(strtotime($this->view->date) * 1000),
        '$lte' => new UTCDateTime((strtotime($this->view->date) + 86400) * 1000),
      ]
    ];
    switch($filter) {
      case "comments":
        $match['permlink'] = ['$regex' => new Regex('^re-', 'i')];
        break;
      case "posts":
        $match['permlink'] = ['$regex' => new Regex('^(?!re-)', 'i')];
        break;
    }
    $this->view->authoring = AuthorReward::find(array(
      $match,
      'sort' => array('_ts' => -1),
      'skip' => $limit * ($page - 1),
      'limit' => $limit,
    ));
    $this->view->pages = ceil(AuthorReward::count(array(
      array('author' => $account)
    )) / $limit);
    $this->view->pick("account/view");
  }

  public function energizeAction()
  {
    $account = $this->getAccount();
    $this->view->energize = VestingDeposit::find(array(
      array('to' => $account),
      'sort' => array('_ts' => -1)
    ));
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function de_energizeAction()
  {
    $account = $this->getAccount();
    $this->view->de_energize = VestingWithdraw::find(array(
      array('from_account' => $account),
      'sort' => array('_ts' => -1)
    ));
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function transfersAction()
  {
    $account = $this->getAccount();
    $this->view->page = $page = (int) $this->request->get("page") ?: 1;
    $limit = 500;
    $this->view->transfers = Transfer::find(array(
      array(
        '$or' => array(
          array('from' => $account),
          array('to' => $account),
        )
      ),
      'sort' => array('_ts' => -1),
      'skip' => $limit * ($page - 1),
      'limit' => $limit,
    ));
    $this->view->pages = ceil(Transfer::count(array(
      array(
        '$or' => array(
          array('from' => $account),
          array('to' => $account),
        )
      ),
    )) / $limit);
    $this->view->chart = true;
    $this->view->pick("account/view");
  }

  public function dataAction()
  {
    $account = $this->getAccount();
    $this->view->pick("account/view");
  }
}
