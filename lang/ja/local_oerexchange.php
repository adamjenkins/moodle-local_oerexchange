<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Japanese language strings for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activesites'] = '有効なサイト';
$string['addreview'] = '活用事例を共有する';
$string['allowlistadd'] = '許可リストに項目を追加';
$string['allowlistbranch'] = 'Moodleブランチ';
$string['allowlistdisable'] = '無効化';
$string['allowlistempty'] = '許可リストにはまだプラグインがありません。';
$string['allowlistenable'] = '有効化';
$string['allowlistpluginname'] = 'プラグイン名（フランケンスタイル、種別プレフィックスなし）';
$string['allowlistplugintype'] = 'プラグインの種類';
$string['allowlistsha256'] = 'SHA-256';
$string['allowlistsourceurl'] = 'ソースZIPのURL';
$string['allowlistupload'] = 'ZIPファイル';
$string['approvesuccess'] = 'サイトを承認しました。サイトキーは {$a} 宛にメールで送信されました。';
$string['attributionchain'] = '改変元: {$a}';
$string['catalogtitle'] = 'OERカタログを閲覧';
$string['catalogueempty'] = 'このExchangeにはまだリソースが共有されていません。';
$string['connectintro'] = 'Exchangeアカウントにサインインするか、新規に作成して、あなたのMoodleサイトと連携してください。';
$string['connectsuccess'] = 'アカウントが連携されました。このウィンドウを閉じてかまいません。';
$string['connecttitle'] = 'Exchangeアカウントを連携';
$string['courseformatlabel'] = 'コース形式: {$a}';
$string['dismissreport'] = '却下';
$string['download'] = 'ダウンロード';
$string['downloadcountlabel'] = 'ダウンロード数: {$a}';
$string['error_backuptoolarge'] = 'このバックアップは許容される最大サイズを超えています。';
$string['error_invalidbranch'] = 'Moodleブランチは、5.2のような単純な「メジャー.マイナー」形式のバージョンで指定してください。';
$string['error_invalidreporttype'] = '無効な報告理由です。';
$string['error_invalidresourcetype'] = '無効なリソース種別です。';
$string['error_invalidsitekey'] = '無効なサイトキーです。';
$string['error_linkcodeexpired'] = 'この連携コードは有効期限が切れています。';
$string['error_linkcodeused'] = 'この連携コードはすでに使用されています。';
$string['error_nofile'] = 'ドラフトエリアにバックアップファイルが見つかりませんでした。';
$string['error_notfound'] = '見つかりません。';
$string['error_notyourresource'] = 'あなたが共有したリソースではありません。';
$string['error_sanitycheckfailed'] = 'このバックアップにはユーザーデータが含まれている可能性があるため、公開できません。ユーザーデータを除外して再エクスポートしてください。';
$string['error_sitenotactive'] = 'このサイトはExchange上で有効になっていません。';
$string['error_sitenotlinked'] = 'あなたのアカウントはこのサイトを通じて連携されていません。';
$string['failedparses'] = '解析に失敗した項目';
$string['filterbytype'] = '種別';
$string['filterlanguage'] = '言語';
$string['filterlicense'] = 'ライセンス';
$string['filtersubject'] = '教科';
$string['generalsettings'] = '全般設定';
$string['hideresource'] = '非表示';
$string['importcountlabel'] = 'インポート数: {$a}';
$string['includedintrial'] = '試用に含まれます';
$string['licenselabel'] = 'ライセンス: {$a}';
$string['managepluginallowlisttitle'] = 'サンドボックスプラグイン許可リスト';
$string['managesitestitle'] = '登録済みサイト';
$string['messageprovider:import'] = '共有したリソースがインポートされた通知';
$string['messageprovider:report'] = '共有したリソースが報告された通知';
$string['messageprovider:review'] = '共有したリソースにレビューが投稿された通知';
$string['missingfromimport'] = 'あなたのサイトにインストールされていません — 事前にインストールしない限り、この活動はスキップされます';
$string['missingfromtrial'] = '試用には含まれません — 「試してみる」では表示されません';
$string['moderatetitle'] = 'モデレーションキュー';
$string['moodleversionlabel'] = '共有元のMoodleバージョン: {$a}';
$string['nocatalogresources'] = '検索条件に一致するリソースがありません。';
$string['nofailedparses'] = '解析に失敗した項目はありません。';
$string['noopenreports'] = '未処理の報告はありません。';
$string['nositesyet'] = 'このカテゴリにはサイトがありません。';
$string['notifyimportbody'] = 'どなたかが、あなたの共有リソース「{$a}」を自分のMoodleサイトにインポートしました。';
$string['notifyimportsubject'] = 'あなたのリソース「{$a}」がインポートされました';
$string['notifyreportbody'] = 'あなたの共有リソース「{$a}」が報告され、現在モデレーションによる確認が行われています。';
$string['notifyreportsubject'] = 'あなたのリソース「{$a}」が報告されました';
$string['notifyreviewbody'] = 'どなたかが、あなたのリソース「{$a}」をどのように活用したかを共有しました。';
$string['notifyreviewsubject'] = '「{$a}」に新しい活用事例が投稿されました';
$string['oerexchange:managesites'] = '登録済みのクライアントサイトおよびサンドボックスプラグインの許可リストを管理する';
$string['oerexchange:moderate'] = '報告および解析失敗の項目をモデレートする';
$string['openreports'] = '未処理の報告';
$string['pendingsites'] = '承認待ちのサイト';
$string['pluginname'] = 'OER Exchange';
$string['privacy:metadata:local_oerexchange_imports'] = 'ユーザーがクライアントサイトでリソースをインポートした記録。';
$string['privacy:metadata:local_oerexchange_imports:timecreated'] = 'インポートが行われた日時。';
$string['privacy:metadata:local_oerexchange_imports:userid'] = 'リソースをインポートしたユーザー。';
$string['privacy:metadata:local_oerexchange_linkcodes'] = 'アカウント連携の手続き中に発行されたワンタイムコードと、それに紐づくウェブサービストークン。';
$string['privacy:metadata:local_oerexchange_linkcodes:timecreated'] = '連携コードが発行された日時。';
$string['privacy:metadata:local_oerexchange_linkcodes:token'] = '連携されたクライアントサイト用に発行されたウェブサービストークン。使用後は消去されます。';
$string['privacy:metadata:local_oerexchange_linkcodes:userid'] = '連携トークンの所有者であるユーザー。';
$string['privacy:metadata:local_oerexchange_reports'] = 'このサイトのユーザーが提出したモデレーション報告。';
$string['privacy:metadata:local_oerexchange_reports:details'] = '報告とともに提供された詳細情報。';
$string['privacy:metadata:local_oerexchange_reports:timecreated'] = '報告が提出された日時。';
$string['privacy:metadata:local_oerexchange_reports:userid'] = '報告を提出したユーザー。';
$string['privacy:metadata:local_oerexchange_resources'] = 'このサイトのユーザーが作成（共有）したリソース。';
$string['privacy:metadata:local_oerexchange_resources:creatorid'] = 'リソースを共有したユーザー。';
$string['privacy:metadata:local_oerexchange_resources:timeshared'] = 'リソースが共有された日時。';
$string['privacy:metadata:local_oerexchange_resources:title'] = 'リソースのタイトル。';
$string['privacy:metadata:local_oerexchange_reviews'] = 'このサイトのユーザーが投稿した活用事例のレビュー。';
$string['privacy:metadata:local_oerexchange_reviews:adaptationtext'] = 'レビューに記載された改変内容。';
$string['privacy:metadata:local_oerexchange_reviews:contexttext'] = 'レビューに記載された授業の文脈。';
$string['privacy:metadata:local_oerexchange_reviews:outcometext'] = 'レビューに記載された結果。';
$string['privacy:metadata:local_oerexchange_reviews:rating'] = '付与された評価。';
$string['privacy:metadata:local_oerexchange_reviews:timecreated'] = 'レビューが作成された日時。';
$string['privacy:metadata:local_oerexchange_reviews:userid'] = 'レビューを投稿したユーザー。';
$string['privacy:metadata:local_oerexchange_trials'] = 'ユーザーがリソースのサンドボックス試用を開始した記録。';
$string['privacy:metadata:local_oerexchange_trials:timecreated'] = '試用が開始された日時。';
$string['privacy:metadata:local_oerexchange_trials:userid'] = '試用を開始したユーザー。';
$string['removeresource'] = '削除';
$string['report'] = '報告';
$string['reportdetails'] = '詳細';
$string['reportsubmit'] = '報告を送信';
$string['reportsubmitted'] = 'ご協力ありがとうございます。報告は確認のため送信されました。';
$string['reporttype'] = '理由';
$string['reporttype_copyright'] = '著作権に関する懸念';
$string['reporttype_other'] = 'その他';
$string['reporttype_quality'] = '品質の問題';
$string['reporttype_spam'] = 'スパム';
$string['requiredplugins'] = '必要なプラグイン';
$string['requiredpluginsnone'] = '追加のプラグインは必要ありません。';
$string['resolvereport'] = '解決';
$string['resolvernote'] = 'メモ';
$string['resourcetitle'] = 'リソース';
$string['reviewadaptation'] = 'どのような変更を加えましたか？';
$string['reviewcontext'] = 'どのように利用しましたか？（例:「看護学科1年生45名」）';
$string['reviewoutcome'] = '結果はどうでしたか？';
$string['reviewrating'] = '評価（任意）';
$string['reviewsheading'] = '活用事例';
$string['reviewsubmit'] = 'レビューを投稿';
$string['reviewsubmitted'] = '活用事例を共有していただきありがとうございます。';
$string['revokedsites'] = '取り消されたサイト';
$string['revokesuccess'] = 'サイトキーを取り消しました。';
$string['searchbutton'] = '検索';
$string['searchplaceholder'] = 'タイトル、概要、タグを検索…';
$string['sectionnumber'] = 'セクション {$a}';
$string['settings_anonymousdownload'] = '匿名でのダウンロードを許可する';
$string['settings_anonymousdownload_desc'] = 'ログインしていない訪問者が、リソースページの「ダウンロード」ボタンから、リソースの.mbzファイルを直接ダウンロードできるようにします。';
$string['settings_anonymousheading'] = '匿名アクセス';
$string['settings_anonymousheading_desc'] = 'カタログの閲覧、リソースの表示、「試してみる」は、ログインしなくてもすでに利用できます。ダウンロードは既定では利用できません — このセクションではその設定を行います。';
$string['settings_sandboxbaseurl'] = 'サンドボックスのベースURL';
$string['settings_sandboxbaseurl_desc'] = 'Moodle Playgroundの静的バンドルが配置されている、同一オリジン上のパス。例: https://vagrant.wisecat.net/try/';
$string['settings_sandboxenabled'] = 'サンドボックスを有効にする（試してみる）';
$string['settings_sandboxenabled_desc'] = '「試してみる」ボタンを表示し、Moodle Playgroundの試用を起動できるようにします。';
$string['settingsheading'] = 'OER Exchange設定';
$string['sharedby'] = '{$a} が共有';
$string['siteapprove'] = '承認';
$string['sitecontact'] = '連絡先メールアドレス';
$string['sitekeyemailbody'] = "あなたのサイト「{\$a->name}」が承認されました。\n\nサイトキー: {\$a->sitekey}\n\nこの値を、あなたのMoodleサイトのOER Clientプラグインの設定に貼り付けて、登録を完了してください。このキーは秘密にしてください — このキーを持つ者は誰でも、あなたのサイトとしてExchangeに公開できます。";
$string['sitekeyemailsubject'] = 'OER Exchangeのサイトキー';
$string['sitename'] = 'サイト名';
$string['siterevoke'] = '取り消し';
$string['sitestatus'] = 'ステータス';
$string['siteurl'] = 'サイトURL';
$string['structurepreview'] = '構成プレビュー';
$string['tryit'] = '試してみる';
$string['tryitloadinghint'] = 'ブラウザ内で完全なMoodleを読み込みます — 初回はしばらく時間がかかります。';
$string['tryitunavailable'] = '「試してみる」は現在利用できません。';
$string['typeactivity'] = '活動';
$string['typecourse'] = 'コース';
