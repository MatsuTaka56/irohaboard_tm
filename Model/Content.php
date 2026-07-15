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
         WHERE h1.id = Record.record_id
         ORDER BY created
          DESC LIMIT 1) as understanding,
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
		for($i=0; $i< count($id_list); $i++)
		{
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
	 * @return int ソート番号
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
	 * @param int		$id		出力するコースまたはコンテンツのID
	 * @param string	$class	出力情報の区分(course/content)
	 * @param string	$fp_csv	出力するCSVのファイルパス
	 * @param array		$files	出力するファイルを格納するフォルダパス
	 * @return array	$files 
	*/
	public function exportContent($id, $class, $fp_csv, $files, $csv_mode = 'a')
	{
		$fp = fopen($fp_csv, $csv_mode);

		//------------------------------//
		//	コンテンツ情報の出力          //
		//------------------------------//
		$section = array();
		$section[] = __('#コンテンツ');
		mb_convert_variables('SJIS-win', 'UTF-8', $section);
		fputcsv($fp, $section);

		//	コンテンツヘッダー行を作成
		$header_list = Configure::read('export_content_header');
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
		if ($class == 'course') 
		{	
			$content_count = $this->find()
				->where(['course_id' => $id])
				->count();	// コンテンツ数を取得
			$page_size  = ceil($content_count / $limit);	// ページ数（コンテンツ数 / ページ単位）
		}
		else 
		{
			$page_size = 1;
		}
		// ページ単位でコンテンツを取得->出力
		for($page=1; $page <= $page_size; $page++)
		{
			// ページ単位でコンテンツ情報を取得
			$this->recursive = 1;
			if ($class == 'course') 
			{	
				$rows = $this->find()
					->where(['course_id' => $id])
					->limit($limit)
					->page($page)
					->order('Content.sort_no asc')
					->all();
			}
			else
			{
				$rows = $this->find()
					->where(['Content.id' => $id])
					->all();
			}
			// コンテンツ情報を出力
			foreach($rows as $row)
			{
				// 出力行を作成
				$line = array();
				foreach ($header_list as $key => $val)
				{
					switch ($key) {
						case 'kind':
							$line[] = Configure::read('content_kind.'.$row['Content']['kind']);
							break;
						case 'status':
							$line[] = Configure::read('content_status.'.$row['Content']['status']);
							break;
						case 'wrong_mode':
							if($row['Content']['kind'] != 'test')
							{
								$line[] = "";
							}
							else
							{
								$line[] = $row['Content'][$key] + 1;
							}
							break;
						case 'mode':
							if(in_array($row['Content']['kind'],['html', 'url', 'movie', 'pict']))
							{
								$line[] = Configure::read('content_mode.'.$row['Content']['wrong_mode']);
							}
							else
							{
								$line[] = "";
							}
							break;
						default:
							$line[] = $row['Content'][$key];
					}
				}

				// CSV出力
				mb_convert_variables('SJIS-win', 'UTF-8', $line);
				fputcsv($fp, $line);

				// 画像、動画、ファイルの場合、ファイル名を抽出
				if(in_array($row['Content']['kind'],['file', 'movie', 'pict']))
				{
					array_push($files, $row['Content']['file_name']);
				}
				// リッチテキストの場合、imageファイル名を抽出
				else if($row['Content']['kind'] == 'html')
				{
					if(preg_match_all('/file_image\/(.+?)\/\d+\"/', $row['Content']['body'], $rich_images) > 0)
					{
						foreach($rich_images[1] as $image)
						{
							array_push($files, $image);
						}
					}
				}
			}
		}

		fclose($fp);

		return $files;
	}
}
