<?php

namespace mpyw\HardBotter;

use mpyw\Cowitter\ClientInterface;

/**
 * 基本メソッド群
 */
interface IBotEssential
{
    const ERRMODE_SILENT    = 0;
    const ERRMODE_WARNING   = 1;
    const ERRMODE_EXCEPTION = 2;

    /**
     * インスタンスを生成するコンストラクタ．
     *
     * @param mpyw\Cowitter\ClientInterface $client
     *          mpyw\Cowitter\Client の認証済みインスタンス．
     * @param string
     *          重複動作を防ぐために種々の情報を記録するファイル．
     *          フォーマットはJSONなので拡張子は「.json」を推奨．
     * @param int $span
     *          最後の実行から $span 秒以内の連続実行を無効化します．
     *          スクリプトをcron用にWWWに公開している場合に安全対策として有用です．
     * @param int $mark_limit
     *          マーク保持数上限です．マーク済みであるとされたツイートは無視されます．
     * @param int $back_limit
     *          遡ることを許可する秒数です．これより古いツイートは無視されます．
     */
    public function __construct(ClientInterface $client, $filename = 'stamp.json', $span = 0, $mark_limit = 10000, $back_limit = 3600);

    /**
     * ツイートのステータスIDをチェック済みであるとしてマークします．
     *
     * @param stdClass $status
     *          マークしたいツイートのステータスオブジェクト．
     */
    public function mark(\stdClass $status);

    /**
     * 間接的に呼び出された mpyw\Cowitter\Client::get/get2/post/postMultipart
     * あるいはそのAsync版，またはOAuthEcho版，またupload系メソッド4種について，
     * エラーハンドリングの方法を設定できます．
     * 設定次第で毎回 try ~ catch を設ける必要が無くなります．
     *
     * @param $mode Bot::ERRMODE_SILENT … 失敗時には何も出力せず，FALSEを返します．
     *              Bot::ERRMODE_WARNING … 失敗時には WARNING を出力し，FALSEを返します．(postのデフォルト)
     *              Bot::ERRMODE_EXCEPTION … 失敗時には mpyw\Cowitter\HttpException
     *                                       または mpyw\Co\CURLException をスローします．(getのデフォルト)
     */
    public function setGetErrorMode($mode);
    public function setPostErrorMode($mode);

    /**
     * マジックメソッド__callを利用してあらゆる
     * mpyw\Cowitter\Client のメソッドを間接的に呼び出すことが出来ます．
     * このクラスを経由して呼び出されたもののうち，
     * mpyw\Cowitter\Client::get/get2/post/postMultipart
     * あるいはそのAsync版の返り値について，
     *
     *    ・マーク済みのツイートは除外されます．
     *    ・$back_limit より古いツイートは除外されます．
     *    ・ツイート本文に含まれる「&amp;」「&lt;」「&gt;」はデコードされ，HTMLからテキストにフォーマットが変わります．
     *    ・ユーザの name または description に含まれるスクリーンネームになり得る「@」は「(at)」に置換されます．
     *
     * @return mixed
     *          失敗時，エラーモードが Bot::ERRMODE_WARNING に設定されている場合はFALSEを返します．
     *
     * @throws mpyw\Cowitter\HttpException
     * @throws mpyw\Co\CURLException
     *          失敗時，エラーモードが Bot::ERRMODE_EXCEPTION に設定されている場合にスローされます．
     */
    public function __call($method, array $args);
}
