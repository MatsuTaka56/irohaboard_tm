<?php
/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('AppModel', 'Model');

/**
 * Content Model
 *
 * @property Group $Group
 * @property Course $Course
 * @property User $User
 * @property Record $Record
 */
class Content extends AppModel
{
	/**
	 * バリデーションルール
	 * https://book.cakephp.org/2/ja/models/data-validation.html
	 * @var array
	 */
	public $validate = [
		'course_id' => [
			'numeric' => [
				'rule' => ['numeric']
			]
		],
		'user_id' => [
			'numeric' => [
				'rule' => ['numeric']
			]
		],
		'title' => [
			'notBlank' => [
				'rule' => ['notBlank']
			]
		],
		'status' => [
			'notBlank' => [
				'rule' => ['notBlank']
			]
		],
		'timelimit' => [
			'numeric' => [
				'rule' => ['range', 0, 101],
				'message' => '1-100の整数で入力して下さい。',
				'allowEmpty' => true,
			]
		],
		'pass_rate' => [
			'numeric' => [
				'rule' => ['range', 0, 101],
				'message' => '1-100の整数で入力して下さい。',
				'allowEmpty' => true,
			]
		],
		'question_count' => [
			'numeric' => [
				'rule' => ['range', 0, 101],
				'message' => '1-100の整数で入力して下さい。',
				'allowEmpty' => true,
			]
		],
		'kind' => [
			'notBlank' => [
				'rule' => ['notBlank']
			]
		],
		'sort_no' => [
			'numeric' => [
				'rule' => ['numeric']
			]
		],
	];

	/**
	 * アソシエーションの設定
	 * https://book.cakephp.org/2/ja/models/associations-linking-models-together.html
	 * @var array
	 */
	public $belongsTo = [
		'Course' => [
			'className' => 'Course',
			'foreignKey' => 'course_id',
			'conditions' => '',
			'fields' => '',
			'order' => ''
		],
		'User' => [
			'className' => 'User',
			'foreignKey' => 'user_id',
			'conditions' => '',
			'fields' => '',
			'order' => ''
		]
	];

	/**
	 * 学習履歴付きコンテンツ一覧を取得
	 * 
	 * @param int $user_id   取得対象のユーザID
	 * @param int $course_id 取得対象のコースID
	 * @param string $role   取得者の権限（admin の場合、非公開のコンテンツも取得）
	 * @var array 学習履歴付きコンテンツ一覧
	 */
	public function getContentRecord($user_id, $course_id, $role = 'user')
	{
		$sql = <<<EOF
 SELECT Content.*, first_date, last_date, record_id, Record.study_sec, Record.study_count,
       (SELECT understanding
          FROM ib_records h1
          WHERE h1.content_id = Record.content_id
          ORDER BY is_complete DESC, created DESC
          LIMIT 1) as understanding,
       (SELECT ifnull(is_passed, 0)
          FROM ib_records h2
         WHERE h2.id = Record.record_id
         ORDER BY created
          DESC LIMIT 1) as is_passed,
        CompleteRecord.is_complete
   FROM ib_contents Content
   LEFT OUTER JOIN # 全ての学習履歴の集計
       (SELECT h.content_id, h.user_id,
               MAX(DATE_FORMAT(created, '%Y/%m/%d')) as last_date,
               MIN(DATE_FORMAT(created, '%Y/%m/%d')) as first_date,
               MAX(id) as record_id,
               SUM(ifnull(study_sec, 0)) as study_sec,
               COUNT(*) as study_count
          FROM ib_records h
         WHERE h.user_id    =:user_id
           AND h.course_id  =:course_id
         GROUP BY h.content_id) Record
     ON Record.content_id  = Content.id
   LEFT OUTER JOIN # 完了した学習履歴の集計
       (SELECT r.content_id, 1 as is_complete #学習履歴をコンテンツ別に集計
          FROM ib_records r
         INNER JOIN ib_contents c ON r.content_id = c.id AND r.course_id = c.course_id
         WHERE r.user_id    = :user_id
           AND r.course_id  =:course_id
           AND c.status = 1
           AND (
                 (c.kind != 'test' AND r.is_complete = 1) OR 
                 (c.kind  = 'test' AND r.is_passed   = 1)
               ) #学習コンテンツが受講済、もしくはテストが合格済の場合
         GROUP BY r.content_id) as CompleteRecord
     ON CompleteRecord.content_id = Content.id
  WHERE Content.course_id  =:course_id
    AND (status = 1 OR 'admin' = :role)
  ORDER BY Content.sort_no
EOF;

		$params = [
			'user_id' => $user_id,
			'course_id' => $course_id,
			'role' => $role
		];

		$data = $this->query($sql, $params);

		return $data;
	}

	/**
	 * コンテンツの並べ替え
	 * 
	 * @param array $id_list コンテンツのIDリスト（並び順）
	 */
	public function setOrder($id_list)
	{
		for ($i = 0; $i < count($id_list); $i++) {
			$sql = "UPDATE ib_contents SET sort_no = :sort_no WHERE id = :id";

			$params = [
				'sort_no' => ($i + 1),
				'id' => $id_list[$i]
			];

			$this->query($sql, $params);
		}
	}

	/**
	 * 新規追加時のコンテンツのソート番号を取得
	 * 
	 * @param array $course_id コースID
	 * @return int  $sort_no ソート番号
	 */
	public function getNextSortNo($course_id)
	{
		$data = $this->find()
			->select('MAX(Content.sort_no) as sort_no')
			->where(['Content.course_id' => $course_id])
			->first();

		$sort_no = $data[0]['sort_no'] + 1;

		return $sort_no;
	}

	/**
	 * コンテンツの削除
	 * 
	 * @param int $content_id 削除するコンテンツのID
	 */
	public function deleteContent($content_id)
	{
		$params = [
			'content_id' => $content_id
		];

		// テスト問題の学習履歴の削除
		$sql = "DELETE FROM ib_records_questions WHERE record_id IN (SELECT id FROM ib_records WHERE content_id = :content_id);";
		$this->query($sql, $params);

		// 学習履歴の削除
		$sql = "DELETE FROM ib_records WHERE content_id = :content_id;";
		$this->query($sql, $params);

		// テスト問題の削除
		$sql = "DELETE FROM ib_contents_questions WHERE content_id = :content_id;";
		$this->query($sql, $params);

		// コンテンツの削除
		$sql = "DELETE FROM ib_contents WHERE id = :content_id;";
		$this->query($sql, $params);
	}

	/**
	 * 論理削除コンテンツの削除
	 * 
	 * @param int $course_id 削除する論理削除コンテンツのコースID
	 */
	public function deleteLogicalDelContent($course_id)
	{
		$params = [
			'course_id' => $course_id
		];

		// コンテンツの削除
		$sql = "DELETE FROM ib_contents WHERE deleted <> '' and course_id = :course_id;";
		$this->query($sql, $params);
	}

	/**
	 * インポートコンテンツの学習履歴を削除
	 * 
	 * @param int $course_id インポートしたコースのID
	 */
	public function deleteRecordImport($course_id)
	{
		$params = [
			'course_id' => $course_id
		];

		// テスト問題の学習履歴の削除
		$sql = "DELETE FROM ib_records_questions WHERE record_id IN (SELECT id FROM ib_records WHERE content_id IN (SELECT id FROM ib_contents WHERE course_id = :course_id));";
		$this->query($sql, $params);

		// 学習履歴の削除
		$sql = "DELETE FROM ib_records WHERE content_id IN (SELECT id FROM ib_contents WHERE course_id = :course_id);";
		$this->query($sql, $params);
	}

	/**
	 * コンテンツ情報の出力
	 * 
	 * @param object	$fp		出力するCSVのファイルハンドル
	 * @param int		$id		出力するコースまたはコンテンツのID
	 * @param string	$class	出力情報の区分(course/content)
	 * @return bool		$is_error	エラー識別（エラー：true, 正常：false）
	 * @return string	$err_msg	エラーメッセージ
	 * @return array	$files 	出力するファイルリスト
	 */
	public function exportContent($fp, $id, $class)
	{
		// 戻り値のエラー情報を設定
		$is_error = false;
		$err_msg = '';
		$files = [];

		//	コンテンツ情報の出力
		// パフォーマンスの改善の為、処理を一定件数に分割（ページ数の算出）
		$limit      = 500;
		if ($class == 'course') {
			$content_count = $this->find()
				->where(['course_id' => $id])
				->count();	// コンテンツ数を取得
			$page_size  = ceil($content_count / $limit);	// ページ数（コンテンツ数 / ページ単位）
		} else {
			$page_size = 1;
		}
		// ページ単位でコンテンツを取得->出力
		for ($page = 1; $page <= $page_size; $page++) {
			// ページ単位でコンテンツ情報を取得
			$this->recursive = 1;
			if ($class == 'course') {
				$rows = $this->find()
					->where(['course_id' => $id])
					->limit($limit)
					->page($page)
					->order('Content.sort_no asc')
					->all();
			} else {
				$rows = $this->find()
					->where(['Content.id' => $id])
					->all();
			}
			// コンテンツ情報を出力
			foreach ($rows as $row) {
				// 出力行を作成
				$line = array();
				if ($row['Content']['kind'] == 'test') {
					$line[] = "3";
					$header_list = Configure::read('export_test_content_header');
				} else {
					$line[] = "2";
					$header_list = Configure::read('export_content_header');
				}
				foreach ($header_list as $key => $val) {
					switch ($key) {
						case 'kind':
							$line[] = Configure::read('content_kind.' . $row['Content']['kind']);
							break;
						case 'status':
							$line[] = Configure::read('content_status.' . $row['Content']['status']);
							break;
						case 'wrong_mode':
							$line[] = $row['Content'][$key] + 1;
							break;
						case 'mode':
							$line[] = Configure::read('content_mode.' . $row['Content']['wrong_mode']);
							break;
						default:
							$line[] = $row['Content'][$key];
					}
				}

				// CSV出力
				mb_convert_variables('SJIS-win', 'UTF-8', $line);
				fputcsv($fp, $line);

				// 画像、動画、ファイルの場合、ファイル名を抽出
				if (in_array($row['Content']['kind'], ['file', 'movie', 'pict'])) {
					array_push($files, $row['Content']['file_name']);
				}
				// リッチテキストの場合、imageファイル名を抽出
				else if ($row['Content']['kind'] == 'html') {
					if (preg_match_all('/file_image\/(.+?)\/\d+\"/', $row['Content']['body'], $rich_images) > 0) {
						foreach ($rich_images[1] as $image) {
							array_push($files, $image);
						}
					}
				}
				if ($row['Content']['kind'] == 'test') {
					$ContentsQuestionModel = ClassRegistry::init('ContentsQuestion');
					list($is_error, $err_msg, $exp_files) = $ContentsQuestionModel->exportQuestion($fp, $row['Content']['id']);
					if ($is_error) {
						return [$is_error, $err_msg, $files];
					} else {
						$files = array_merge($files, $exp_files);
					}
				}
			}
		}

		return [$is_error, $err_msg, $files];
	}

	/**
	 * コンテンツ情報の入力
	 * 
	 * @param int		$course_id	入力するコースまたはコンテンツのID
	 * @param array		$csv		入力するCSVデータ
	 * @param int		$line_index	CSVデータのカレントライン
	 * @param string	$import_mode	コンテンツの登録モード（追加：a、置換：r）
	 * @return bool		$is_error	エラー識別（エラー：true, 正常：false）
	 * @return string	$err_msg	エラーメッセージ
	 * @return array	$files		インポートファイルリスト
	 */
	public function importContent($course_id, $csv, $line_index, $import_mode)
	{
		// 戻り値のエラー情報を設定
		$is_error = false;
		$err_msg = '';
		$add_files = [];

		// 指定コースのコンテンツ状況を確認
		$contents_course = $this->find()
			->where(['Content.course_id' => $course_id])
			->all();
		$sort_next_no = count($contents_course) + 1;

		// 既存データが存在し、置換モードの場合、該当コースのコンテンツに削除フラグを立てる
		if ($import_mode == 'r') {
			foreach ($contents_course as $content_dell) {
				$content_dell['Content']['deleted'] = date('Y-m-d H:i:s');	//削除日付の設定
				$content_dell['Content']['status'] = 0;						//非表示の設定
				if (!$this->save($content_dell)) {
					// 保存時にエラーが発生した場合、モデルからエラー情報を抽出
					$err_list = $this->Content->validationErrors;
					foreach ($err_list as $index => $err) {
						$err_msg .= '<li>' . $index . '件目 : ' . $err[0] . '</li>';
					}
					$is_error = true;
					return [$is_error, $err_msg, $add_files];
				}
			}
			$sort_next_no = 1;
		}
		// content_idの最大値を検索(登録時の初期値を設定）)
		$ex_data = $this->find()
				->order('Content.id desc')
				->first();
		if (!$ex_data){
			$content_next_id = 1;
		} else {
			$content_next_id = $ex_data['Content']['id'] + 1;
		}

		// 列名：列番号の定義
		$header_list = Configure::read('import_content_header');
		$col_no = 1;
		$col_list = [];
		foreach ($header_list as $key => $val) {
			$col_list[2][$val] = $col_no;
			$col_no++;
		}
		$header_list = Configure::read('import_test_content_header');
		$col_no = 1;
		foreach ($header_list as $key => $val) {
			$col_list[3][$val] = $col_no;
			$col_no++;
		}

		// CSVファイルを解析
		$comment_flg = false;
		$count_csv = count($csv);

		while ($line_index < $count_csv) {
			$line_no = $line_index + 1;
			$row = $csv[$line_index];
			$line_index++;

			// コメントチェック
			if ($this->comment_check($row, $comment_flg)) continue;
			
			if (in_array($row[0], ['2', '3'], true)){
				// コンテンツ共通処理
				// content行ごとにデータを登録
				$data = [];
				$data['Content'] = [];
				$this->create();

				if ($import_mode == 'r') {
					// 既存コンテンツ（削除レコード）の確認
					$ex_data = $this->find()
						->where(['Content.course_id' => $course_id])
						->where(['Content.deleted !=' => null])
						->first();
					// 指定したコースIDおよび削除日付有の既存コンテンツが存在しない場合、新規追加とする
					if (!$ex_data) {
						$data['Content']['created'] = date('Y-m-d H:i:s');
						$data['Content']['id'] = $content_next_id;
						$content_next_id++;
					} else {
						$data['Content']['id'] = $ex_data['Content']['id'];
						$data['Content']['created'] = $ex_data['Content']['created'];
					}
				} else {
					$data['Content']['created'] = date('Y-m-d H:i:s');
					$data['Content']['id'] = $content_next_id;
					$content_next_id++;
				}

				// コンテンツ登録データ作成処置
				$data['Content']['course_id'] = $course_id;
				$data['Content']['user_id'] = 1;
				// コンテンツ名チェック
				if ($row[$col_list[$row[0]]['title']] === null) {
					$is_error = true;
					$err_msg .= '<li>' . $line_no . '行目 : コンテンツ名が指定されていません。</li>';
					break;
				}
				$data['Content']['title'] = $row[$col_list[$row[0]]['title']];
				// コンテンツ種別チェック
				if (Utils::getKeyByValue('content_kind', $row[$col_list[$row[0]]['kind']]) === null) {
					$is_error = true;
					$err_msg .= '<li>' . $line_no . '行目 : コンテンツ種別が指定されていません。</li>';
					break;
				}
				$data['Content']['kind'] = Utils::getKeyByValue('content_kind', $row[$col_list[$row[0]]['kind']]);
				// status
				if (Utils::getKeyByValue('content_status', $row[$col_list[$row[0]]['status']]) === null) {
					$data['Content']['status'] = 1;
				} else {
					$data['Content']['status'] = Utils::getKeyByValue('content_status', $row[$col_list[$row[0]]['status']]);
				}
				// その他
				$data['Content']['opened'] = null;
				//$data['Content']['created'] = $row[COL_created];
				$data['Content']['modified'] = date('Y-m-d H:i:s');
				$data['Content']['deleted'] = null;
				$data['Content']['sort_no'] = $sort_next_no;
				$sort_next_no++;
				$data['Content']['comment'] = Utils::issetOr($row[$col_list[$row[0]]['comment']]);

				if ($row[0] == 2) {			// コンテンツラインか？ 先頭列が「2」 
					// コンテンツ処置
					// URLチェック（種別：URL）
					$data['Content']['url'] = "";
					if (in_array($data['Content']['kind'], ['url'])) {
						if ($row[$col_list[$row[0]]['url']] === null) {
							$is_error = true;
							$err_msg .= '<li>' . $line_no . '行目 : URLが指定されていません(' . $data['Content']['kind'] . ')。</li>';
							break;
						} else {
							$data['Content']['url'] = $row[$col_list[$row[0]]['url']];
						}
					}
					// ファイルチェック（種別：動画、画像、ファイル）
					$data['Content']['file_name'] = "";
					if (in_array($data['Content']['kind'], ['movie', 'file', 'pict'])) {
						if ($row[$col_list[$row[0]]['file_name']] === null) {
							$is_error = true;
							$err_msg .= '<li>' . $line_no . '行目 : ファイル名が指定されていません(' . $data['Content']['kind'] . ')。</li>';
							break;
						} else {
							$data['Content']['file_name'] = $row[$col_list[$row[0]]['file_name']];
							array_push($add_files, $data['Content']['file_name']);
						}
					}
					// ファイルチェック（種別：HTML）
					if (in_array($data['Content']['kind'], ['html'])) {
						list($data['Content']['body'], $ex_files) = $this->adaptImportHtml($row[$col_list[$row[0]]['body']], $course_id);
						$add_files = array_merge($add_files, $ex_files);
					}
					$data['Content']['timelimit'] = "";
					$data['Content']['pass_rate'] = "";
					$data['Content']['question_count'] = "";
					$data['Content']['wrong_mode'] = 1;
					if (in_array($data['Content']['kind'], ['html', 'url', 'movie', 'pict'])) {
						if ($row[$col_list[$row[0]]['mode']] != null) {
							$data['Content']['wrong_mode'] = Utils::getKeyByValue('content_mode', $row[$col_list[$row[0]]['mode']]);
						}
					} else if (in_array($data['Content']['kind'], ['label', 'file'])) {
						$data['Content']['wrong_mode'] = 0;
					} 
				} else {					// テストコンテンツラインか？ 先頭列が「3」
					// テストコンテンツ処置
					list($is_error, $err_msg) = $this->testNumCheck($row[$col_list[$row[0]]['timelimit']], 1, 100, $line_no, 'テスト制限時間');
					if ($is_error) break;
					$data['Content']['timelimit'] = $row[$col_list[$row[0]]['timelimit']];

					list($is_error, $err_msg) = $this->testNumCheck($row[$col_list[$row[0]]['pass_rate']], 1, 100, $line_no, '合格得点率 ');
					if ($is_error) break;
					$data['Content']['pass_rate'] = $row[$col_list[$row[0]]['pass_rate']];	

					list($is_error, $err_msg) = $this->testNumCheck($row[$col_list[$row[0]]['question_count']], 1, 100, $line_no, '出題数 ');
					if ($is_error) break;
					$data['Content']['question_count'] = $row[$col_list[$row[0]]['question_count']];

					if ($row[$col_list[$row[0]]['wrong_mode']] == null) {
						$data['Content']['wrong_mode'] = 1;
					} else {
						list($is_error, $err_msg) = $this->testNumCheck($row[$col_list[$row[0]]['wrong_mode']], 1, 3, $line_no, '不正解時の表示');
						if ($is_error) break;
						$data['Content']['wrong_mode'] = $row[$col_list[$row[0]]['wrong_mode']] - 1;
					}

					// テスト問題処理を呼び出し
					$ContentsQuestionModel = ClassRegistry::init('ContentsQuestion');
					list($is_error, $err_msg, $files) = $ContentsQuestionModel->importQuestion($course_id, $data['Content']['id'], $csv, $line_index, $import_mode);
					if ($is_error) {
						return [$is_error, $err_msg, $add_files];
					} else {
						$add_files = array_merge($add_files, $files);
					}
				} 
				//------------------------------//
				//	保存						//
				//------------------------------//
				if (!$this->save($data)) {
					// 保存時にエラーが発生した場合、モデルからエラー情報を抽出
					$err_list = $this->Content->validationErrors;
					foreach ($err_list as $err) {
						$err_msg .= '<li>' . $line_no . '行目 : ' . $err[0] . '</li>';
					}
					$is_error = true;
					break;
				}
			}
		}
		return [$is_error, $err_msg, $add_files];
	}

}
