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
 * ContentsQuestion Model
 *
 * @property Group $Group
 * @property Content $Content
 */
class ContentsQuestion extends AppModel
{
	/**
	 * バリデーションルール
	 * https://book.cakephp.org/2/ja/models/data-validation.html
	 * @var array
	 */
	public $validate = [
		'content_id' => [
			'numeric' => [
				'rule' => ['numeric']
			]
		],
	//	'question_type' => [
	//		'notBlank' => [
	//			'rule' => ['notBlank']
	//		]
	//	],
		'body' => [
			'notBlank' => [
				'rule' => ['notBlank']
			]
		],
		'score' => [
			'numeric' => [
				'rule' => ['range', -1, 101],
				'message' => '0-100の整数で入力して下さい。',
			]
		],
		'sort_no' => [
			'numeric' => [
				'rule' => ['numeric']
			]
		],
		'option_list' => [
			'rule' => ['multiple', ['min' => 1,]],
			'message' => '正解を選択してください'
		]
	];
	
	/**
	 * アソシエーションの設定
	 * https://book.cakephp.org/2/ja/models/associations-linking-models-together.html
	 * @var array
	 */
	public $belongsTo = [
		'Content' => [
			'className' => 'Content',
			'foreignKey' => 'content_id',
			'conditions' => '',
			'fields' => '',
			'order' => ''
		]
	];

	/**
	 * 問題の並べ替え
	 * 
	 * @param array $id_list 問題のIDリスト（並び順）
	 */
	public function setOrder($id_list)
	{
		for($i=0; $i< count($id_list); $i++)
		{
			$sql = "UPDATE ib_contents_questions SET sort_no = :sort_no WHERE id = :id";

			$params = [
				'sort_no' => ($i + 1),
				'id' => $id_list[$i]
			];

			$this->query($sql, $params);
		}
	}

	/**
	 * 新規追加時の問題のソート番号を取得
	 * 
	 * @param array $content_id コンテンツ(テスト)のID
	 * @return int  $sort_no ソート番号
	 */
	public function getNextSortNo($content_id)
	{
		$data = $this->find()
			->select('MAX(ContentsQuestion.sort_no) as sort_no')
			->where(['ContentsQuestion.content_id' => $content_id])
			->first();
		
		$sort_no = $data[0]['sort_no'] + 1;
		
		return $sort_no;
	}

	/**
	 * 問題コンテンツの削除
	 * 
	 * @param int $question_id 削除する問題コンテンツのID
	 */
	public function deleteContentsQuestion($question_id)
	{
		$params = [
			'question_id' => $question_id
		];
		
		// テスト問題の学習履歴の削除
		$sql = "DELETE FROM ib_records_questions WHERE question_id = :question_id;";
		$this->query($sql, $params);

		// テスト問題の削除
		$sql = "DELETE FROM ib_contents_questions WHERE id = :question_id;";
		$this->query($sql, $params);
	}

	/**
	 * 論理削除テスト問題の削除
	 * 
	 * @param int $content_id 削除する論理削除テスト問題のテストコンテンツID
	 */
	public function deleteLogicalDelQuestion($content_id)
	{
		$params = [
			'content_id' => $content_id
		];
		// 論理削除されたテスト問題の削除
		$sql = "DELETE FROM ib_contents_questions WHERE sort_no = 99999 and content_id = :content_id;";
		$this->query($sql, $params);
	}

	/**
	 * インポート問題コンテンツの学習履歴を削除
	 * 
	 * @param int $content_id インポートした問題コンテンツのID
	 */
	public function deleteRecordImport($content_id)
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
	}
		
	/**
	 * テスト情報の出力
	 * 
	 * @param object	$fp			出力するCSVのファイルハンドル
	 * @param array		$id_content	出力するコンテンツのID
	 * @return bool		$is_error	エラー識別（エラー：true, 正常：false）
	 * @return string	$err_msg	エラーメッセージ
	 * @return array	$files 		出力するファイルリスト
	*/
	public function exportQuestion($fp, $id_content)
	{
		$is_error = false;
		$err_msg = '';
		$files = [];

		//	問題情報の出力               //
		// パフォーマンスの改善の為、処理を一定件数に分割（ページ数の算出）
		$limit      = 500;
		$contentsQuestion_count = $this->find()
				->where(['content_id' => $id_content])
				->count();	// テスト問題数を取得
		$page_size = ceil($contentsQuestion_count / $limit);	// ページ数（テスト問題数 / ページ単位）
		
		// ページ単位でテスト問題を取得->出力
		for($page=1; $page <= $page_size; $page++)
		{
			// ページ単位でテスト問題情報を取得
			$this->recursive = 1;
			$rows = $this->find()
				->where(['content_id' => $id_content])
				->limit($limit)
				->page($page)
				->all();
			// 問題情報を出力
			$header_list = Configure::read('export_test_question_header');
			foreach($rows as $row)
			{
				// 出力行を作成
				$line = array();
				$line[] = "4";
				foreach ($header_list as $key => $val)
				{
					$line[] = $row['ContentsQuestion'][$key];
				}

				// CSV出力
				mb_convert_variables('SJIS-win', 'UTF-8', $line);
				fputcsv($fp, $line);

				// 問題文中のファイル名を抽出
				if(preg_match_all('/file_image\/(.+?)\/\d+\"/', $row['ContentsQuestion']['body'], $rich_images) > 0)
				{
					foreach($rich_images[1] as $image)
					{
						array_push($files, $image);
					}
				}
				// 解説文中のファイル名を抽出
				if(preg_match_all('/file_image\/(.+?)\/\d+\"/', $row['ContentsQuestion']['explain'], $rich_images) > 0)
				{
					foreach($rich_images[1] as $image)
					{
						array_push($files, $image);
					}
				}
			}
		}

		return [$is_error, $err_msg, $files];
	}

	/**
	 * テスト問題情報の入力
	 * 
	 * @param int		$course_id	入力するコースのID
	 * @param int		$content_id	入力するコンテンツのID
	 * @param array		$csv		入力するCSVデータ
	 * @param int		$line_index	CSVデータのカレントライン
	 * @param string	$import_mode	コンテンツの登録モード（追加：a、置換：r）
	 * @return bool		$is_error	エラー識別（エラー：true, 正常：false）
	 * @return string	$err_msg	エラーメッセージ
	 * @return array	$files		インポートファイルリスト
	*/
	public function importQuestion($course_id, $content_id, $csv, &$line_index, $import_mode = 'a')
	{
		// 戻り値のエラー情報を設定
		$is_error = false;
		$err_msg = '';
		$add_files = [];

		// 指定テストコンテンツのテスト問題状況を確認
		$questions_content = $this->find()
			->where(['ContentsQuestion.content_id' => $content_id])
			->all();
		$sort_next_no = count($questions_content) + 1;

		// 既存データが存在し、置換モードの場合、該当コンテンツの問題コンテンツに削除フラグ（ダミー）を立てる
		if ($import_mode == 'r') {
			foreach ($questions_content as $question_dell) {
				$question_dell['ContentsQuestion']['sort_no'] = 99999;		// 仮の削除設定
				$question_dell['ContentsQuestion']['modified'] = date('Y-m-d H:i:s');
				if (!$this->save($question_dell)) {
					// 保存時にエラーが発生した場合、モデルからエラー情報を抽出
					$err_list = $this->validationErrors;
					foreach ($err_list as $index => $err) {
						$err_msg .= '<li>' . $index . '件目 : ' . $err[0] . '</li>';
					}
					$is_error = true;
					return [$is_error, $err_msg, $add_files];
				}
			}
			$sort_next_no = 1;
		}

		// 列名：列番号の定義
		//------------------------------//
		$header_list = Configure::read('import_test_question_header');
		$col_no = 1;
		$col_list = [];
		foreach ($header_list as $key => $val) {
			$col_list[$val] = $col_no;
			$col_no++;
		}

		// CSVファイルを解析
		$comment_flg = false;
		$content4_flag = false;
		$count_csv = count($csv);

		while ($line_index < $count_csv) {
			$line_no = $line_index + 1;
			$row = $csv[$line_index];
			$line_index++;

			// コメントチェック
			if ($this->comment_check($row, $comment_flg)) continue;

			if ($row[0] == 4) {		// テスト問題ラインか？ 先頭列が「4」
				$content4_flag = true;
				$data = [];
				$data['ContentsQuestion'] = [];
				$this->create();
				
				if ($import_mode == 'r') {
					// 既存テスト問題（仮削除レコード）の確認
					$ex_data = $this->find()
						->where(['ContentsQuestion.content_id' => $content_id])
						->where(['ContentsQuestion.sort_no' => 99999])
						->first();
					
					// 指定したコンテンツIDおよび仮の削除識別(sort_no=99999)の既存コンテンツが存在しない場合、新規追加とする
					if(!$ex_data) {
						$data['ContentsQuestion']['created'] = date('Y-m-d H:i:s');
					} else {
						$data['ContentsQuestion']['id'] = $ex_data['ContentsQuestion']['id'];
						$data['ContentsQuestion']['created'] = $ex_data['ContentsQuestion']['created'];
					}
				} else {
					$data['ContentsQuestion']['created'] = date('Y-m-d H:i:s');
				}
					
				// インポートデータの指定の有無を確認しながらテスト問題登録データを作成する
				$data['ContentsQuestion']['content_id'] = $content_id;
				$data['ContentsQuestion']['title'] = $row[$col_list['title']];
				if($row[$col_list['body']] === null) {
					$is_error = true;
					$err_msg .= '<li>'.$line_no.'行目 : 問題文が指定されていません。</li>';
					break;
				}
				// リッチテキスト内のコースIDをインポート先のコースIDに変更し、インポートファイルを抽出する
				$data['ContentsQuestion']['body'] = $row[$col_list['body']];
				// ファイルチェック（種別：HTML）
				list($data['ContentsQuestion']['body'], $ex_files) = $this->adaptImportHtml($row[$col_list['body']], $course_id);
				$add_files = array_merge($add_files, $ex_files);

				$data['ContentsQuestion']['image'] = $row[$col_list['image']];			// ファイル名
				if($row[$col_list['options']] === null) {
					$is_error = true;
					$err_msg .= '<li>'.$line_no.'行目 : 選択肢が指定されていません。</li>';
					break;
				}
				$data['ContentsQuestion']['options'] = $row[$col_list['options']];		// 選択肢
				if($row[$col_list['correct']] === null) {
					$is_error = true;
					$err_msg .= '<li>'.$line_no.'行目 : 正解が指定されていません。</li>';
					break;
				}
				$data['ContentsQuestion']['correct'] = $row[$col_list['correct']];
				if($row[$col_list['score']] === null) {
					$is_error = true;
					$err_msg .= '<li>'.$line_no.'行目 : 得点が指定されていません。</li>';
					break;
				}
				$data['ContentsQuestion']['score'] = $row[$col_list['score']];
				list($data['ContentsQuestion']['explain'], $ex_files) = $this->adaptImportHtml($row[$col_list['explain']], $course_id);
				$add_files = array_merge($add_files, $ex_files);

				$data['ContentsQuestion']['sort_no'] = $sort_next_no;
				$sort_next_no++;
				$data['ContentsQuestion']['comment'] = $row[$col_list['comment']];
				$data['ContentsQuestion']['modified'] = date('Y-m-d H:i:s');
				//------------------------------//
				//	保存						//
				//------------------------------//
				if(!$this->save($data)) {
					// 保存時にエラーが発生した場合、モデルからエラー情報を抽出
					$err_list = $this->ContentsQuestion->validationErrors;
					foreach($err_list as $err) {
						$err_msg .= '<li>'.$line_no.'行目 : '.$err[0].'</li>';
					}
					$is_error = true;
					break;
				}
			} else if (!$content4_flag) {
				continue;
			} else if ($content4_flag && $row[0] == '' ) {
				continue;
			} else{
				$line_index--;
				return [$is_error, $err_msg, $add_files];
			}		
		}
		return [$is_error, $err_msg, $add_files];
	}
	
}
