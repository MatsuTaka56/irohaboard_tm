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
		'question_type' => [
			'notBlank' => [
				'rule' => ['notBlank']
			]
		],
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
	 * @return int ソート番号
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
	 * @param array		$ids_content	出力するコンテンツのIDリスト
	 * @param string	$fp_csv			出力するCSVのファイルパス
	 * @param array		$files			出力するファイルを格納するフォルダパス
	 * @return array	$files 
	*/
	public function exportQuestion($ids_content, $fp_csv, $files)
	{
		$fp = fopen($fp_csv,'a');

		//------------------------------//
		//	問題情報の出力               //
		//------------------------------//
		$section = array();
		$section[] = __('#テスト問題');
		mb_convert_variables('SJIS-win', 'UTF-8', $section);
		fputcsv($fp, $section);

		//	問題コンテンツヘッダー行の作成
		$header_list = Configure::read('export_content_question_header');
		$header = array();
		foreach ($header_list as $key => $val)
		{
			$header[] = __($val.' ');
		}
		// ヘッダー行をCSV出力
		mb_convert_variables('SJIS-win', 'UTF-8', $header);
		fputcsv($fp, $header);
		
		// パフォーマンスの改善の為、処理を一定件数に分割（ページ数の算出）
		$limit      = 500;
		$contentsQuestion_count = $this->find()
				->where(['content_id IN' => (array)$ids_content])
				->count();	// テスト問題数を取得
		$page_size = ceil($contentsQuestion_count / $limit);	// ページ数（テスト問題数 / ページ単位）
		
		// ページ単位でテスト問題を取得->出力
		for($page=1; $page <= $page_size; $page++)
		{
			// ページ単位でテスト問題情報を取得
			$this->recursive = 1;
			$rows = $this->find()
				->where(['content_id IN' => (array)$ids_content])
				->limit($limit)
				->page($page)
				->all();
			// 問題情報を出力
			foreach($rows as $row)
			{
				// 出力行を作成
				$line = array();
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
		
		fclose($fp);

		return $files;
	}
}
