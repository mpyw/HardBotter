<?php

namespace mpyw\HardBotter;

use mpyw\Cowitter\Client;
use mpyw\Co\Co;

class Bot implements IBotEssential, IbotHelper
{
    private $client;
    private $file;
    private $prev;
    private $marked = [];
    private $mark_limit = 10000;
    private $back_limit = 3600;
    private $get_error_mode = self::ERRMODE_EXCEPTION;
    private $post_error_mode = self::ERRMODE_WARNING;

    /**
     * コンストラクタ
     */
    final public function __construct(
        Client $client, $filename = 'stamp.json',
        $span = 0, $mark_limit = 10000, $back_limit = 3600
    ) {
        // ヘッダの送出
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        // エラー表示の設定
        error_reporting(-1);
        ini_set('log_errors', PHP_SAPI === 'cli');
        ini_set('display_errors', PHP_SAPI !== 'cli');
        // 重複起動防止
        $file = new \SplFileObject($filename, 'a+b');
        if (!$file->flock(LOCK_EX | LOCK_UN)) {
            throw new \RuntimeException('Failed to lock file.');
        }
        // コンテンツを取得
        $json = $file->getSize() > 0
            ? json_decode($file->fread($file->getSize()), true)
            : [];
        // JSONに前回実行時刻が保存されていた時
        if (isset($json['prev'])) {
            // 十分に時間が空いたかどうかをチェック
            if (!self::expired($json['prev'], $span)) {
                throw new \RuntimeException('Execution span is not enough.');
            }
        }
        // JSONにマーク済みステータス一覧が記録されていたとき復元する
        if (isset($json['marked'])) {
            $this->marked = array_map('filter_var', (array)$json['marked']);
        }
        $this->client = $client;
        $this->file = $file;
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->prev = $now->format('r');
        $this->mark_limit = $mark_limit;
        $this->back_limit = $back_limit;
    }

    /**
     * デストラクタ
     */
    final public function __destruct()
    {
        $this->file->ftruncate(0);
        $this->file->fwrite(json_encode([
            'prev' => $this->prev,
            // 収まりきらない古い情報は破棄する
            'marked' => array_slice($this->marked, -$this->mark_limit, $this->mark_limit, true),
        ]));
        $this->file->flock(LOCK_UN);
    }

    /**
     * 間接的に mpyw\Cowitter\Client のメソッドをコールするメソッド
     */
    final public function __call($method, array $args)
    {
        $callback = [$this->client, $method];
        if (!is_callable($callback)) {
            throw new \BadMethodCallException("Call to undefined method mpyw\Cowitter\Client::$method()");
        }
        try {
            // レスポンスをフィルタリングして返す
            $result = call_user_func_array($callback, $args);
            return $result instanceof \Generator
                ? $this->filterAsync($method, $result)
                : $this->filter($method, $result);
        } catch (\RuntimeException $e) {
            // モードに応じて例外処理方法を分岐
            return $this->handleException($method, $e);
        }
    }
    private function filterAsync($method, $task)
    {
        try {
            // レスポンスをフィルタリングして返す
            yield Co::RETURN_WITH => $this->filter($method, (yield $task));
        } catch (\RuntimeException $e) {
            // モードに応じて例外処理方法を分岐
            yield Co::RETURN_WITH => $this->handleException($method, $e);
        }
    }

    /**
     * レスポンスのフィルタメソッド
     */
    private function filter($method, $variable)
    {
        static $callback;
        if (!$callback) {
            // 全てのステータスをarray_walk_recursiveを利用して処理するクロージャ
            $callback = function (&$value, $key) {
                if ($key === 'text') {
                    // ツイート本文のHTML特殊文字をデコードする
                    $value = htmlspecialchars_decode($value, ENT_NOQUOTES);
                } elseif ($key === 'name' || $key === 'description') {
                    // プロフィールの名前および詳細に含まれるスクリーンネームの「@」を「(at)」に置換する
                    $value = preg_replace('/@(?=\w{1,15}+)/', '(at)', $value);
                }
            };
        }
        // get, post, postMultipart 以外のメソッドは処理しない
        if (!preg_match('/^(?:get|post|postMultipart)(?:Async)?$/i', $method)) {
            return $variable;
        }
        // 一度連想配列に変換した後再帰的にフィルタリング処理をかけ，もとに戻す
        $variable = (array)json_decode(json_encode($variable), true);
        array_walk_recursive($variable, $callback);
        $variable = json_decode(json_encode($variable));
        // get 以外のメソッドはこれ以上処理しない
        if (!preg_match('/^get(?:Async)?$/i', $method)) {
            return $variable;
        }
        // 取得してきたものがステータス配列あるいはステータス配列を含むオブジェクトの場合，
        // その配列を処理対象にする
        if (is_array($variable)) {
            $list = &$variable;
        } elseif (isset($variable->statuses) && is_array($variable->statuses)) {
            $list = &$variable->statuses;
        }
        // 処理対象の配列が空でなかったとき
        if (!empty($list)) {
            foreach ($list as $i => $status) {
                switch (true) {
                    case !isset($status->text, $status->id_str, $status->created_at):
                        continue;
                    // マーク済みのステータスは除外する
                    case isset($this->marked[$status->id_str]):
                    // 期限切れのステータスは除外する
                    case self::expired($status->created_at, $this->back_limit):
                        unset($list[$i]);
                }
            }
        }
        return $variable;
    }

    /**
     * 例外ハンドラ
     */
    private function handleException($method, \RuntimeException $e)
    {
        if (preg_match('/^get(?:Async)?$/i', $method)) {
            // getのとき
            if ($this->get_error_mode & self::ERRMODE_WARNING) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
            if ($this->get_error_mode & self::ERRMODE_EXCEPTION) {
                throw $e;
            }
            return false;
        } elseif (preg_match('/^(?:post|postMultipart)(?:Async)?$/i', $method)) {
            // postのとき
            if ($this->post_error_mode & self::ERRMODE_WARNING) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
            if ($this->post_error_mode & self::ERRMODE_EXCEPTION) {
                throw $e;
            }
            return false;
        } else {
            // それ以外
            throw $e;
        }
    }

    /**
     * マーク
     */
    final public function mark(\stdClass $status) {
        $this->marked[$status->id_str] = true;
    }

    /**
     * エラーモード
     */
    final public function setGetErrorMode($mode) {
        $this->get_error_mode = $mode;
    }
    final public function setPostErrorMode($mode) {
        $this->post_error_mode = $mode;
    }

    /**
     * パターンマッチング
     */
    final public static function match($text, array $pairs)
    {
        static $callback;
        if (!$callback) {
            // 文字列で指定された場合に置換フォーマットをパースして処理するクロージャ
            $callback = function ($m) use (&$matches) {
                $key = isset($m[2]) ? $m[2] : $m[1];
                return isset($matches[$key]) ? $matches[$key] : '';
            };
        }
        // 連想配列の先頭からチェック
        foreach ($pairs as $pattern => $value) {
            if (preg_match($pattern, $text, $matches)) {
                if (is_scalar($value)) {
                    // 文字列で指定された場合
                    return preg_replace_callback('/\$(?:\{([^}]*+)}|(\d++))/', $callback, $value);
                } elseif (is_callable($value)) {
                    // クロージャで指定された場合
                    return $value($matches);
                } else {
                    // 指定がなかった場合
                    return null;
                }
            }
        }
    }

    /**
     * リプライ
     */
    final public function reply($text, \stdClass $original_status, $prepend_screen_name = true)
    {
        $result = $this->post('statuses/update', [
            'status' => $prepend_screen_name ? "@{$original_status->user->screen_name} $text" : $text,
            'in_reply_to_status_id' => $original_status->id_str,
        ]);
        if ($result !== false) {
            self::out('UPDATED: ' . $result->text);
        }
        return $result;
    }
    final public function replyAsync($text, \stdClass $original_status, $prepend_screen_name = true)
    {
        $result = (yield $this->postAsync('statuses/update', [
            'status' => $prepend_screen_name ? "@{$original_status->user->screen_name} $text" : $text,
            'in_reply_to_status_id' => $original_status->id_str,
        ]));
        if ($result !== false) {
            self::out('UPDATED: ' . $result->text);
        }
        yield Co::RETURN_WITH => $result;
    }

    /**
     * オートページャ
     */
    final public function collect($endpoint, $followable_page_count, array $params = [])
    {
        // カーソルに-1を指定する（不要な場合に余分なパラメータとしてつけても問題ない）
        $params['cursor'] = '-1';
        // 初回の結果を取得
        $result = $this->get($endpoint, $params);
        if ($result === false) {
            return false;
        }
        if (is_array($result)) {
            // 結果自体が配列である場合，max_idでのページングを行う
            // 配列を結果として格納
            $list = $result;
            for ($i = 0; $i < $followable_page_count; ++$i) {
                $end = end($result);
                if (!isset($end->id_str)) {
                    // 追うものがなくなった時点で脱出
                    break;
                }
                // 最終IDから1を引く
                $params['max_id'] = bcsub($end->id_str, 1);
                // 次の結果を取得してマージ
                $result = $this->get($endpoint, $params);
                if ($result === false) {
                    return false;
                }
                $list = array_merge($list, $result);
            }
            return $list;
        } elseif (isset($result->statuses) && is_array($result->statuses)) {
            // 結果statusesプロパティが配列である場合，max_idでのページングを行う
            // 配列部分のみを結果として格納
            $list = $result->statuses;
            for ($i = 0; $i < $followable_page_count; ++$i) {
                $end = end($result->statuses);
                if (!isset($end->id_str)) {
                    // 追うものがなくなった時点で脱出
                    break;
                }
                // 最終IDから1を引く
                $params['max_id'] = bcsub($end->id_str, 1);
                // 次の結果を取得してマージ
                $result = $this->get($endpoint, $params);
                if ($result === false) {
                    return false;
                }
                $list = array_merge($list, $result->statuses);
            }
            return $list;
        } elseif (isset($result->next_cursor_str)) {
            // カーソルが存在する場合，cursorでのページングを行う
            // 配列であるプロパティの名前を求める
            $prop = key(array_filter((array)$result, 'is_array'));
            // 配列部分のみを結果として格納
            $list = $result->$prop;
            for ($i = 0; $i < $followable_page_count; ++$i) {
                if (empty($result->next_cursor_str)) {
                    // 追うものがなくなった時点で脱出
                    return $list;
                }
                // カーソルを進める
                $params['cursor'] = $params->next_cursor_str;
                // 次の結果を取得してマージ
                $result = $this->get($endpoint, $params);
                if ($result === false) {
                    return false;
                }
                $list = array_merge($list, $result->$prop);
            }
            return $list;
        } else {
            // それ以外の場合はそもそもこのメソッドを使うべきではない
            throw new \BadMethodCallException('Response is not compatible.');
        }
    }
    final public function collectAsync($endpoint, $followable_page_count, array $params = [])
    {
        // カーソルに-1を指定する（不要な場合に余分なパラメータとしてつけても問題ない）
        $params['cursor'] = '-1';
        // 初回の結果を取得
        $result = (yield $this->getAsync($endpoint, $params));
        if ($result === false) {
            yield Co::RETURN_WITH => false;
        }
        if (is_array($result)) {
            // 結果自体が配列である場合，max_idでのページングを行う
            // 配列を結果として格納
            $list = $result;
            for ($i = 0; $i < $followable_page_count; ++$i) {
                $end = end($result);
                if (!isset($end->id_str)) {
                    // 追うものがなくなった時点で脱出
                    break;
                }
                // 最終IDから1を引く
                $params['max_id'] = bcsub($end->id_str, 1);
                // 次の結果を取得してマージ
                $result = (yield $this->getAsync($endpoint, $params));
                if ($result === false) {
                    yield Co::RETURN_WITH => false;
                }
                $list = array_merge($list, $result);
            }
            yield Co::RETURN_WITH => $list;
        } elseif (isset($result->statuses) && is_array($result->statuses)) {
            // 結果statusesプロパティが配列である場合，max_idでのページングを行う
            // 配列部分のみを結果として格納
            $list = $result->statuses;
            for ($i = 0; $i < $followable_page_count; ++$i) {
                $end = end($result->statuses);
                if (!isset($end->id_str)) {
                    // 追うものがなくなった時点で脱出
                    break;
                }
                // 最終IDから1を引く
                $params['max_id'] = bcsub($end->id_str, 1);
                // 次の結果を取得してマージ
                $result = $this->get($endpoint, $params);
                if ($result === false) {
                    yield Co::RETURN_WITH => false;
                }
                $list = array_merge($list, $result->statuses);
            }
            yield Co::RETURN_WITH => $list;
        } elseif (isset($result->next_cursor_str)) {
            // カーソルが存在する場合，cursorでのページングを行う
            // 配列であるプロパティの名前を求める
            $prop = key(array_filter((array)$result, 'is_array'));
            // 配列部分のみを結果として格納
            $list = $result->$prop;
            for ($i = 0; $i < $followable_page_count; ++$i) {
                if (empty($result->next_cursor_str)) {
                    // 追うものがなくなった時点で脱出
                    yield Co::RETURN_WITH => $list;
                }
                // カーソルを進める
                $params['cursor'] = $params->next_cursor_str;
                // 次の結果を取得してマージ
                $result = $this->get($endpoint, $params);
                if ($result === false) {
                    yield Co::RETURN_WITH => false;
                }
                $list = array_merge($list, $result->$prop);
            }
            yield Co::RETURN_WITH => $list;
        } else {
            // それ以外の場合はそもそもこのメソッドを使うべきではない
            throw new \BadMethodCallException('Response is not compatible.');
        }
    }

    /**
     * 相互フォロー
     */
    final public function forceMutuals($followable_page_count = INF)
    {
        list($friends, $followers) = array_map(
            'array_flip',
            [
                $this->collect('friends/ids', $followable_page_count, ['stringify_ids' => true]),
                $this->collect('followers/ids', $followable_page_count, ['stringify_ids' => true]),
            ]
        );
        $friends_only = array_diff_key($friends, $followers);
        $followers_only = array_diff_key($followers, $friends);
        $result = true;
        foreach ($friends_only as $id => $_) {
            $result = $this->unfollow($id) && $result;
        }
        foreach ($followers_only as $id => $_) {
            $result = $this->follow($id) && $result;
        }
        return $result;
    }
    final public function forceMutualsAsync($followable_page_count = INF)
    {
        list($friends, $followers) = array_map(
            'array_flip',
            (yield [
                $this->collectAsync('friends/ids', $followable_page_count, ['stringify_ids' => true]),
                $this->collectAsync('followers/ids', $followable_page_count, ['stringify_ids' => true]),
            ])
        );
        $friends_only = array_diff_key($friends, $followers);
        $followers_only = array_diff_key($followers, $friends);
        $result = true;
        $tasks = [];
        foreach ($friends_only as $id => $_) {
            $tasks[] = $this->unfollowAsync($id);
        }
        foreach ($followers_only as $id => $_) {
            $tasks[] = $this->followAsync($id);
        }
        yield Co::RETURN_WITH => !in_array(false, (yield $tasks), true);
    }

    /**
     * その他の補助
     */
    public function tweet($text)
    {
        $result = $this->post('statuses/update', ['status' => $text]);
        if ($result !== false) {
            self::out('TWEETED: ' . $result->text);
        }
        return $result;
    }
    public function tweetAsync($text)
    {
        $result = (yield $this->postAsync('statuses/update', ['status' => $text]));
        if ($result !== false) {
            self::out('TWEETED: ' . $result->text);
        }
        yield Co::RETURN_WITH => $result;
    }
    public function favorite(\stdClass $status)
    {
        $result = $this->post('favorites/create', ['id' => $status->id_str]);
        if ($result !== false) {
            self::out('FAVORITED: ' . $result->text);
        }
        return $result;
    }
    public function favoriteAsync(\stdClass $status)
    {
        $result = (yield $this->postAsync('favorites/create', ['id' => $status->id_str]));
        if ($result !== false) {
            self::out('FAVORITED: ' . $result->text);
        }
        yield Co::RETURN_WITH => $result;
    }
    public function retweet(\stdClass $status)
    {
        $result = $this->post('statuses/retweet', ['id' => $status->id_str]);
        if ($result !== false) {
            self::out('RETWEETED: ' . $status->text);
        }
        return $result;
    }
    public function retweetAsync(\stdClass $status)
    {
        $result = (yield $this->postAsync('statuses/retweet', ['id' => $status->id_str]));
        if ($result !== false) {
            self::out('RETWEETED: ' . $status->text);
        }
        yield Co::RETURN_WITH => $result;
    }
    public function follow($user_id)
    {
        $result = $this->post('friendships/create', ['user_id' => $user_id]);
        if ($result !== false) {
            self::out('FOLLOWED: @' . $result->screen_name);
        }
        return $result;
    }
    public function followAsync($user_id)
    {
        $result = (yield $this->postAsync('friendships/create', ['user_id' => $user_id]));
        if ($result !== false) {
            self::out('FOLLOWED: @' . $result->screen_name);
        }
        yield Co::RETURN_WITH => $result;
    }
    public function unfollow($user_id)
    {
        $result = $this->post('friendships/destroy', ['user_id' => $user_id]);
        if ($result !== false) {
            self::out('UNFOLLOWED: @' . $result->screen_name);
        }
        return $result;
    }
    public function unfollowAsync($user_id)
    {
        $result = (yield $this->postAsync('friendships/destroy', ['user_id' => $user_id]));
        if ($result !== false) {
            self::out('UNFOLLOWED: @' . $result->screen_name);
        }
        yield Co::RETURN_WITH => $result;
    }

    /**
     * $limit + $span と現在を比較して期限が過ぎているかどうかをチェック
     */
    private static function expired($limit, $span) {
        $span = (int)$span;
        $limit = new \DateTime($limit, new \DateTimeZone('UTC'));
        $limit->add(new \DateInterval("PT{$span}S"));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        return $limit <= $now;
    }

    /**
     * 結果出力用
     */
    private static function out($msg)
    {
        if (PHP_SAPI === 'cli') {
            echo $msg . PHP_EOL;
        } else {
            echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '<br>' . PHP_EOL;
        }
    }

    /**
     * clone および serialize 対策
     */
    final public function __sleep()
    {
        throw new \BadMethodCallException('Instances are not serializable.');
    }
    final public function __clone()
    {
        throw new \BadMethodCallException('Instances are not clonable.');
    }
}