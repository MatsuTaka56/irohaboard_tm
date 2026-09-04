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
 * Course Model
 *
 * @property Group $Group
 * @property Content $Content
 * @property ContentsQuestion $contentsQuestion
 * @property Record $Record
 * @property User $User
 */
class Course extends AppModel
{
	public $order = "Course.sort_no"; // デフォルトのソート条件

	/**
	 * バリデーションルール
	 * https://book.cakephp.org/2/ja/models/data-validation.html
	 * @var array
	 */
	public $validate = [
		'title'   => ['notBlank' => ['rule' => ['notBlank']]],
		'sort_no' => ['numeric'  => ['rule' => ['numeric']]]
	];

	/**
	 * アソシエーションの設定
	 * https://book.cakephp.org/2/ja/models/associations-linking-models-together.html
	 * @var array
	 */
	public $hasMany = [
		'Content' => [
			'className' => 'Content',
			'foreignKey' => 'course_id',
			'dependent' => false,
			'conditions' => '',
			'fields' => '',
			'order' => '',
			'limit' => '',
			'offset' => '',
			'exclusive' => '',
			'finderQuery' => '',
			'counterQuery' => ''
		]
	];

	/**
	 * コースの並べ替え
	 * 
	 * @param array $id_list コースのIDリスト（並び順）
	 */
	public function setOrder($id_list)
	{
		for($i=0; $i< count($id_list); $i++)
		{
			$sql = "UPDATE ib_courses SET sort_no = :sort_no WHERE id= :id";

			$params = [
				'sort_no' => ($i + 1),
				'id' => $id_list[$i]
			];

			$this->query($sql, $params);
		}
	}
	
	/**
	 * コースへのアクセス権限チェック
	 * 
	 * @param int $user_id   アクセス者のユーザID
	 * @param int $course_id アクセス先のコースのID
	 * @return bool $has_right true: アクセス可能, false : アクセス不可
	 */
	public function hasRight($user_id, $course_id)
	{
		$has_right = false;
		
		$params = [
			'user_id'   => $user_id,
			'course_id' => $course_id
		];
		
		$sql = <<<EOF
SELECT count(*) as cnt
  FROM ib_users_courses
 WHERE course_id = :course_id
   AND user_id   = :user_id
EOF;
		$data = $this->query($sql, $params);
		
		if($data[0][0]['cnt'] > 0)
			$has_right = true;
		
		$sql = <<<EOF
SELECT count(*) as cnt
  FROM ib_groups_courses gc
 INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id AND ug.user_id   = :user_id
 WHERE gc.course_id = :course_id
EOF;
		$data = $this->query($sql, $params);
		
		if($data[0][0]["cnt"] > 0)
			$has_right = true;
		
		return $has_right;
	}
	
	/**
	 * コースの削除
	 * 
	 * @param int $course_id 削除するコースのID
	 */
	public function deleteCourse($course_id)
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
		
		// テスト問題の削除
		$sql = "DELETE FROM ib_contents_questions WHERE content_id IN (SELECT id FROM ib_contents WHERE course_id = :course_id);";
		$this->query($sql, $params);
		
		// コンテンツの削除
		$sql = "DELETE FROM ib_contents WHERE course_id = :course_id;";
		$this->query($sql, $params);
		
		// コースの削除
		$sql = "DELETE FROM ib_courses WHERE id = :course_id;";
		$this->query($sql, $params);

		// 関連ファイル類の削除
		$folder_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$course_id;
		$rmdir_cmd = 'rmdir  /s /q ' . $folder_path;
		exec($rmdir_cmd, $out_mes, $return);
		if ($return != 0){ //0 or それ以外
			//$this->Flash->success(__('ファイルの削除が失敗しました。'));
		}
	}
		
	/**
	 * コース情報の出力
	 * 
	 * @param int $fp			出力するCSVのファイルハンドル
	 * @param int $course_id	出力するコースのID
	 */
	public function exportCourse($fp, $course_id)
	{
		// 戻り値のエラー情報を設定
		$is_error = false;
		$err_msg = '';

		// コース情報出力行を作成
		$course =  $this->find()
			->where(['Course.id' => $course_id])
			->all();
		$line = array();
		$line[] = __('1');
		$header_list = Configure::read('export_course_header');
		foreach ($header_list as $key => $val)
		{
			$line[] = $course[0]['Course'][$key];
		}
		// CSV出力
		mb_convert_variables('SJIS-win', 'UTF-8', $line);
		fputcsv($fp, $line);
		
		return [$is_error, $err_msg];
	}

	/**
	 * 関連ファイルの登録(import)
	 * 
	 * @param int $course_id	登録するコースのID
	 * @param string $zipfile	登録するファイルを含んだZipファイル
	 * @param array $add_files	登録する関連ファイルのリスト
	 */
	public function importFiles($course_id, $zipfile, $add_files)
	{
		// ZIPファイル内部の必要ファイルを抽出=>保存する
		$is_error = false;
		$err_msg = '';

		// 保存ディレクトリの設定
		$course_dir = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$course_id.DS;
		if (!file_exists($course_dir))
		{	// 存在しなければ作成
			mkdir($course_dir, 0777, true);
		}

		// ZIPファイルの読み込み=>ファイル名抽出=>$add_filesに含まれるファイルの場合保存
		$zip = new ZipArchive();
		if ($zip->open($zipfile['tmp_name']) === TRUE)
		{	// ZIPファイルがオープンできれば、処理継続
			// ZIP内のファイルを走査
			for ($i = 0; $i < $zip->numFiles; $i++)
			{
				$entry = $zip->getNameIndex($i);
				// ディレクトリはスキップ
				if (substr($entry, -1) === '/')
				{	// ディレクトリ
					continue;
				}
				// ファイル名を取り出して必要ファイルかを判定
				$basename = basename($entry);
				if (in_array(mb_strtolower($basename), array_map('mb_strtolower', $add_files), true))
				{	// 必要ファイル
					// 必要な動画、画像、配布資料ファイルだけ保存
					$content = $zip->getFromIndex($i);
					file_put_contents($course_dir.$basename, $content);
				}
			}
			$zip->close();
			// ZIPファイルを開放
			unlink($zipfile['tmp_name']);
		}
		else
		{
			$is_error = true;
			$err_msg .= '<li>画像、動画、配布資料、イメージ用のZIPファイルが読むことができません。</li>';
		}
		return [$is_error, $err_msg];
	}
		
	/**
	 * ヘッダー情報の出力
	 * 
	 * @param int $fp    出力するCSVのファイルハンドル
	 */
	public function exportHeader($fp)
	{
		// 戻り値のエラー情報を設定
		$is_error = false;
		$err_msg = '';

		//	Header情報の説明出力             //
		$section = array();
		$section[] = __('/* 各行は以下のフォームで出力されます。１はコース情報、２はコンテンツ情報、３はテストコンテンツ情報、４はテスト問題情報です。');
		mb_convert_variables('SJIS-win', 'UTF-8', $section);
		fputcsv($fp, $section);

		//	コースヘッダー行の作成
		$this->csvHeaderOut($fp, Configure::read('export_course_header'), '1');
		//	コンテンツヘッダー行の作成
		$this->csvHeaderOut($fp, Configure::read('export_content_header'), '2');
		//	テストコンテンツヘッダー行の作成
		$this->csvHeaderOut($fp, Configure::read('export_test_content_header'), '3');
		//	テスト問題ヘッダー行の作成
		$this->csvHeaderOut($fp, Configure::read('export_test_question_header'), '4');

		$section = array();
		$section[] = __('*/');
		mb_convert_variables('SJIS-win', 'UTF-8', $section);
		fputcsv($fp, $section);

		return [$is_error, $err_msg];
	}

	private function csvHeaderOut($fp, $header_list, $line_name)
	{
		$header = array();
		$header[] = __($line_name.' ');
		foreach ($header_list as $key => $val)
		{
			$header[] = __($val.' ');
		}
		// テスト問題ヘッダー行をCSV出力
		mb_convert_variables('SJIS-win', 'UTF-8', $header);
		fputcsv($fp, $header);
	}

	/**
	 * コンテンツ情報の入力
	 * 
	 * @param int		$course_id	入力するコースまたはコンテンツのID
	 * @param array		$csv		入力するCSVデータ
	 * @return bool		$is_error	エラー識別（エラー：true, 正常：false）
	 * @return string	$err_msg	エラーメッセージ
	 * @return array	$files		インポートファイルリスト
	 */
	public function importCourse(&$course_id, $csv)
	{
		// 戻り値のエラー情報を設定
		$is_error = false;
		$err_msg = '';
		$add_files = [];
		$line_index = 0;
		$import_mode = 'a';

		// course_idの最大値を検索(登録時の初期値を設定）)
		$ex_data = $this->find()
				->order('Course.id desc')
				->first();
		if (!$ex_data){
			$course_id = 1;
		} else {
			$course_id = $ex_data['Course']['id'] + 1;
		}

		// 列名：列番号の定義
		$header_list = Configure::read('import_course_header');
		$col_no = 1;
		$col_list = [];
		foreach ($header_list as $key => $val) {
			$col_list[1][$val] = $col_no;
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
			
			if (in_array($row[0], ['1'], true)){
				// コース情報を登録
				$data = [];
				$data['Course'] = [];
				$this->create();
				// コース登録データ作成処置
				$data['Course']['id'] = $course_id;
				// コンテンツ名チェック
				if ($row[$col_list[$row[0]]['title']] === null) {
					$is_error = true;
					$err_msg .= '<li>' . $line_no . '行目 : コース名が指定されていません。</li>';
					break;
				}
				$data['Course']['title'] = $row[$col_list[$row[0]]['title']];
				$data['Course']['introduction'] = Utils::issetOr($row[$col_list[$row[0]]['introduction']]);
				$data['Course']['comment'] = Utils::issetOr($row[$col_list[$row[0]]['comment']]);
				$data['Content']['opened'] = null;
				$data['Course']['created'] = date('Y-m-d H:i:s');
				$data['Course']['modified'] = date('Y-m-d H:i:s');
				$data['Course']['deleted'] = null;
				$data['Course']['sort_no'] = 0;
				$data['Course']['user_id'] = 1;

				// コンテンツ処理を呼び出し
				$ContentModel = ClassRegistry::init('Content');
				list($is_error, $err_msg, $add_files) = $ContentModel->importContent($course_id, $csv, $line_index, $import_mode);
				if ($is_error) {
					return [$is_error, $err_msg, $add_files];
				}
				//------------------------------//
				//	保存						//
				//------------------------------//
				if (!$this->save($data)) {
					// 保存時にエラーが発生した場合、モデルからエラー情報を抽出
					$err_list = $this->Course->validationErrors;
					foreach ($err_list as $err) {
						$err_msg .= '<li>' . $line_no . '行目 : ' . $err[0] . '</li>';
					}
					$is_error = true;
				}
				break;
			}
		}
		return [$is_error, $err_msg, $add_files];
	}

}