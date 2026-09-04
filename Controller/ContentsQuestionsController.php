<?php
/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('AppController', 'Controller');

/**
 * ContentsQuestions Controller
 * https://book.cakephp.org/2/ja/controllers.html
 */
class ContentsQuestionsController extends AppController
{
	/**
	 * 使用するコンポーネント
	 * https://book.cakephp.org/2/ja/core-libraries/toc-components.html
	 */
	public $components = [
		'Security' => [
			'validatePost' => false,
			'csrfUseOnce' => false,
			//'csrfCheck' => false,
			'csrfExpires' => '+3 hours',
			'csrfLimit' => 10000,
		],
	];

	/**
	 * 問題を出題
	 * @param int $content_id 表示するコンテンツ(テスト)のID
	 * @param int $record_id 履歴ID (テスト結果表示の場合、指定)
	 */
	public function index($content_id, $record_id = null)
	{
		$this->ContentsQuestion->recursive = 0;
	
		$content_id = intval($content_id);
		$record_id = ($record_id != null) ? intval($record_id) : null;
		
		//------------------------------//
		//	コンテンツ情報を取得		//
		//------------------------------//
		$this->fetchTable('Content');
		$content = $this->fetchTable('Content')->get($content_id);
		
		//------------------------------//
		//	権限チェック				//
		//------------------------------//
		// 管理ページ以外の場合、コンテンツの閲覧権限の確認
		if(!$this->isAdminPage())
		{
			if(!$this->fetchTable('Course')->hasRight($this->readAuthUser('id'), $content['Content']['course_id']))
				throw new NotFoundException(__('Invalid access'));
		}
		
		// 管理者以外の場合、非公開コンテンツへのアクセスを禁止
		if($this->readAuthUser('role') != 'admin' && $content['Content']['status'] != 1)
		{
			throw new NotFoundException(__('Invalid access'));
		}
		
		//------------------------------//
		//	問題情報を取得				//
		//------------------------------//
		$record = null;
		
		if($record_id != null) // テスト結果表示モードの場合
		{
			// テスト結果情報を取得
			$this->fetchTable('Record');
			$record = $this->Record->get($record_id);
			
			// 受講者によるテスト結果表示の場合、自身のテスト結果か確認
			if(!$this->isAdminPage() && $this->isRecordPage() && ($record['Record']['user_id'] != $this->readAuthUser('id')))
			{
				throw new NotFoundException(__('Invalid access'));
			}
			
			// テスト結果に紐づく問題ID一覧（出題順）を作成
			// 問題が存在しない場合のエラーを防ぐため、0を追加
			$question_id_list = [0];
			
			foreach($record['RecordsQuestion'] as $question)
			{
				$question_id_list[] = $question['question_id'];
			}
			
			// 問題ID一覧を元に問題情報を取得
			$contentsQuestions = $this->ContentsQuestion->find()
				->where(['content_id' => $content_id, 'ContentsQuestion.id' => $question_id_list])
				->order('FIELD(ContentsQuestion.id,'.implode(',', $question_id_list).')')  // 指定したID順で並び替え
				->all();
			
			//debug($contentsQuestions);
		}
		else if($this->readSession('Iroha.RondomQuestions.'.$content_id.'.id_list') != null) // 既にランダム出題情報がセッション上にある場合
		{
			// セッションにランダム出題情報が存在する場合、その情報を使用
			$question_id_list = $this->readSession('Iroha.RondomQuestions.'.$content_id.'.id_list');
			
			$contentsQuestions = $this->ContentsQuestion->find()
				->where(['content_id' => $content_id, 'ContentsQuestion.id' => $question_id_list])
				->order('FIELD(ContentsQuestion.id,'.implode(',', $question_id_list).')') // 指定したID順で並び替え
				->all();
		}
		else if($content['Content']['question_count'] > 0) // ランダム出題の場合
		{
			// ランダム出題情報を取得
			$contentsQuestions = $this->ContentsQuestion->find()
				->where(['content_id' => $content_id])
				->limit($content['Content']['question_count']) // 出題数
				->order('rand()') // 乱数で並び替え
				->all();
			
			// 問題IDの一覧を作成
			$question_id_list = [];
			
			foreach($contentsQuestions as $contentsQuestion)
			{
				$question_id_list[] = $contentsQuestion['ContentsQuestion']['id'];
			}
			
			// ランダム出題情報を一時的にセッションに格納（リロードによる変化や、採点時の問題情報との矛盾を防ぐため）
			$this->writeSession('Iroha.RondomQuestions.'.$content_id.'.id_list', $question_id_list);
		}
		else // 通常の出題の場合
		{
			// 全ての問題情報を取得（通常の処理）
			$contentsQuestions = $this->ContentsQuestion->find()
				->where(['content_id' => $content_id])
				->order('ContentsQuestion.sort_no asc')
				->all();
		}
		
		//------------------------------//
		//	採点処理					//
		//------------------------------//
		if($this->request->is('post'))
		{
			$details	= [];									// 成績詳細情報
			$full_score	= 0;									// 最高点
			$pass_score	= 0;									// 合格基準点
			$my_score	= 0;									// 得点
			$pass_rate	= $content['Content']['pass_rate'];		// 合格得点率
			
			//------------------------------//
			//	成績の詳細情報の作成		//
			//------------------------------//
			foreach($contentsQuestions as $contentsQuestion)
			{
				$question_id	= $contentsQuestion['ContentsQuestion']['id'];		// 問題ID
				$answer			= $this->getData('answer_'.$question_id);			// 解答（複数選択問題の場合、配列）
				
				$correct		= $contentsQuestion['ContentsQuestion']['correct'];	// 正解
				$corrects		= explode(',', $correct);							// 複数選択問題の正解（配列）
				
				$score			= $contentsQuestion['ContentsQuestion']['score'];	// 配点
				
				
				// 複数選択問題の場合
				if(count($corrects) > 1)
				{
					// 全ての解答と正解が一致するか確認
					$is_correct	= $this->isMultiCorrect($answer, $corrects) ? 1 : 0;
					
					// データベース格納用に解答をカンマ区切りの文字列に変更
					$answer		= is_array($answer) ? implode(',', $answer) : null;
				}
				else
				{
					$is_correct	= ($answer == $correct) ? 1 : 0;
				}
				
				// 合計点（配点の合計）
				$full_score += $score;
				
				// 得点（正解した問題の配点の合計）
				if($is_correct == 1)
					$my_score += $score;
				
				// 問題の正誤
				$details[] = [
					'question_id'	=> $question_id,	// 問題ID
					'answer'		=> $answer,			// 解答
					'correct'		=> $correct,		// 正解
					'is_correct'	=> $is_correct,		// 正誤
					'score'			=> $score,			// 配点
				];
			}
			
			// 合格基準得点
			$pass_score = ($full_score * $pass_rate) / 100;
			
			// 合格基準得点を超えていた場合、合格とする
			$is_passed = ($my_score >= $pass_score) ? 1 : 0;
			
			// テスト実施時間
			$study_sec = $this->getData('ContentsQuestion')['study_sec'];
			
			$this->fetchTable('Record');
			$this->Record->create();
			
			// 追加する成績情報
			$data = [
				'user_id'		=> $this->readAuthUser('id'),					// ログインユーザのユーザID
				'course_id'		=> $content['Course']['id'],					// コースID
				'content_id'	=> $content_id,									// コンテンツID
				'full_score'	=> $full_score,									// 合計点
				'pass_score'	=> $pass_score,									// 合格基準得点
				'score'			=> $my_score,									// 得点
				'is_passed'		=> $is_passed,									// 合否判定
				'study_sec'		=> $study_sec,									// テスト実施時間
				'is_complete'	=> 1
			];
			
			//------------------------------//
			//	テスト結果の保存			//
			//------------------------------//
			if($this->Record->save($data))
			{
				$this->fetchTable('RecordsQuestion');
				$record_id = $this->Record->getLastInsertID();
				
				// 問題単位の成績を保存
				foreach($details as $detail)
				{
					$this->RecordsQuestion->create();
					$detail['record_id'] = $record_id;
					$this->RecordsQuestion->save($detail);
				}
				
				// ランダム出題用の問題IDリストを削除
				$this->deleteSession('Iroha.RondomQuestions.'.$content_id.'.id_list');
				
				$this->redirect([
					'action' => 'record',
					$content_id,
					$this->Record->getLastInsertID()
				]);
			}
		}
		
		$is_record = $this->isRecordPage();	// テスト結果表示フラグ
		$is_admin_record = $this->isAdminPage() && $this->isRecordPage();
		
		$this->set(compact('content', 'contentsQuestions', 'record', 'is_record', 'is_admin_record'));
	}

	/**
	 * テスト結果を表示
	 * @param int $content_id 表示するコンテンツ(テスト)のID
	 * @param int $record_id 履歴ID
	 */
	public function record($content_id, $record_id)
	{
		$content_id = intval($content_id);
		$record_id = intval($record_id);
		
		$this->index($content_id, $record_id);
		$this->render('index');
	}

	/**
	 * テスト結果を表示
	 * @param int $content_id 表示するコンテンツ(テスト)のID
	 * @param int $record_id 履歴ID
	 */
	public function admin_record($content_id, $record_id)
	{
		$this->record($content_id, $record_id);
	}

	/**
	 * 問題一覧を表示
	 * @param int $content_id 表示するコンテンツ(テスト)のID
	 */
	public function admin_index($content_id)
	{
		$content_id = intval($content_id);
		
		$this->ContentsQuestion->recursive = 0;
		$contentsQuestions = $this->ContentsQuestion->find()
			->where(['ContentsQuestion.content_id' => $content_id])
			->order('ContentsQuestion.sort_no asc')
			->all();
		
		// コンテンツ情報を取得
		$this->fetchTable('Content');
		
		$content = $this->fetchTable('Content')->get($content_id);
		
		$this->set(compact('content', 'contentsQuestions'));
	}

	/**
	 * 問題を追加
	 * @param int $content_id 追加対象のコンテンツ(テスト)のID
	 */
	public function admin_add($content_id)
	{
		$this->admin_edit($content_id);
		$this->render('admin_edit');
	}

	/**
	 * 問題を編集
	 * @param int $content_id 追加対象のコンテンツ(テスト)のID
	 * @param int $question_id 編集対象の問題のID
	 */
	public function admin_edit($content_id, $question_id = null)
	{
		$content_id = intval($content_id);
		
		if($this->isEditPage() && !$this->ContentsQuestion->exists($question_id))
		{
			throw new NotFoundException(__('Invalid contents question'));
		}

		// コンテンツ情報を取得
		$content = $this->fetchTable('Content')->get($content_id);
		
		if($this->request->is(['post', 'put']))
		{
			if($question_id == null)
			{
				$this->request->data['ContentsQuestion']['user_id'] = $this->readAuthUser('id');
				$this->request->data['ContentsQuestion']['content_id'] = $content_id;
				$this->request->data['ContentsQuestion']['sort_no']   = $this->ContentsQuestion->getNextSortNo($content_id);
			}
			
			if(!$this->ContentsQuestion->validates())
				return;
			
			if($this->ContentsQuestion->save($this->request->data))
			{
				$this->Flash->success(__('問題が保存されました'));
				return $this->redirect([
					'controller' => 'contents_questions',
					'action' => 'index',
					$content_id
				]);
			}
			else
			{
				$this->Flash->error(__('The contents question could not be saved. Please, try again.'));
			}
		}
		else
		{
			$this->request->data = $this->ContentsQuestion->get($question_id);
		}
		
		$this->set(compact('content'));
	}

	/**
	 * 問題を削除
	 * @param int $question_id 削除対象の問題のID
	 */
	public function admin_delete($question_id = null)
	{
		$this->ContentsQuestion->id = $question_id;
		
		if(!$this->ContentsQuestion->exists())
		{
			throw new NotFoundException(__('Invalid contents question'));
		}
		
		$this->request->allowMethod('post', 'delete');
		
		// 問題情報を取得
		$question = $this->ContentsQuestion->get($question_id);
		
		$this->ContentsQuestion->deleteContentsQuestion($question_id);
		
		$this->Flash->success(__('問題が削除されました'));
		
		return $this->redirect([
			'controller' => 'contents_questions',
			'action' => 'index',
			$question['ContentsQuestion']['content_id']
		]);
		
		return $this->redirect(['action' => 'index']);
	}

	/**
	 * Ajax によるコンテンツの並び替え
	 *
	 * @return string 実行結果
	 */
	public function admin_order()
	{
		$this->autoRender = FALSE;
		
		if($this->request->is('ajax'))
		{
			$this->ContentsQuestion->setOrder($this->data['id_list']);
			return 'OK';
		}
	}

	// 複数選択問題の正誤判定
	private function isMultiCorrect($answers, $corrects)
	{
		// 解答が設定されていない場合、不正解
		if(!isset($answers))
			return false;
		
		// 解答がnullの場合、不正解
		if($answers == null)
			return false;
		
		// 解答数と正解数が一致しない場合、不正解
		if(count($answers) != count($corrects))
			return false;
		
		// 解答が正解に含まれるか確認
		for($i =0; $i < count($answers); $i++)
		{
			if(!in_array($answers[$i], $corrects))
				return false;
		}
		
		// 全て含まれていれば正解
		return true;
	}

	/**
	 * テスト問題情報のエクスポート
	 */
	public function admin_export($content_id)
	{
		// エラー情報を設定
		$is_error = false;
		$err_msg = '';

		// コンテンツの情報を取得
		$content = $this->fetchTable('Content')->get($content_id);
		$content_name = $content['Content']['title'];
		$course_id = $content['Content']['course_id'];
		// コースの情報を取得
		$course = $this->fetchTable('Course')->get($course_id);
		$course_name = $course['Course']['title'];
		
		$this->autoRender = false;
		Configure::write('debug', 0);

		// エクスポート用のzipオブジェクト、CSVファイル、作業用一時フォルダなどを定義
		$zip_obj = new ZipArchive;
		$tmp_dir = ROOT.DS.APP_DIR.DS.'files'.'/tmp';
		$files_name = $course_name.'_'.$content_name.'_files_'.date('Ymd').'.zip';
		$files = array();
		$csv_name = $course_name.'_'.$content_name.'_csv_'.date('Ymd').'.csv';
		$exp_name = $course_name.'_'.$content_name.'_exp_'.date('Ymd').'.zip';
		if(!is_dir($tmp_dir))
		{
			mkdir($tmp_dir, 0755);
		}

		$fp = fopen($tmp_dir.DS.$csv_name, 'w');

		// 出力する情報のヘッダ情報(course,content,content_test,question)を出力
		list($is_error, $err_msg) = $this->fetchTable('Course')->exportHeader($fp);
		if($is_error){
			$this->Flash->error(__($err_msg));
			$this->set(compact('err_msg'));
			return;
		}

		// コンテンツ情報を出力（１テストコンテンツの全テスト問題）
		list($is_error, $err_msg, $files) = $this->fetchTable('Content')->exportContent($fp, $content_id, 'content');
		if($is_error){
			$this->Flash->error(__($err_msg));
			$this->set(compact('err_msg'));
			return;
		}

		// 問題文、解説文中のimageファイルがあれば、zipにまとめる
		if(count($files) != 0)
		{
			$result = $zip_obj->open($tmp_dir.DS.$files_name, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE);
			if(!$result){
				$this->Flash->error(__('ZIPファイルがオープンできません'));
				$this->set(compact('err_msg'));
				return;
			}
			foreach($files as $file)
			{
				$zip_obj->addFile(ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$course_id.DS.$file, $file);
			}
			$zip_obj->close();
		}

		// 全体をzipでまとめて、ダウンロードする
		// エクスポート用ZIPファイルをオープン
		$result = $zip_obj->open($tmp_dir.DS.$exp_name, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE);
		if(!$result){
			$this->Flash->error(__('ZIPファイルがオープンできません'));
			$this->set(compact('err_msg'));
			return;
		}
		// エクスポート用ZIPファイルにCSVファイルを追加		
		$zip_obj->addFile($tmp_dir.DS.$csv_name, $csv_name);
		// エクスポート用ZIPファイルに関連ファイル類のZIPファイルを追加
		if(count($files) != 0)
		{
			$zip_obj->addFile($tmp_dir.DS.$files_name, $files_name);
		}
		// エクスポート用ZIPファイルをクローズ
		$zip_obj->close();
		//ダウンロード
		header('Content-Type: application/force-download;');
		header('Content-Length: '.filesize($tmp_dir.DS.$exp_name));
		header('Content-Disposition: attachment; filename="'.$exp_name.'"');
		$result = readfile($tmp_dir.DS.$exp_name);
		if($result == false)
		{
			$this->Flash->error(__('ZIPファイルが読み込みできません'));
			$this->set(compact('err_msg'));
			return;
		}
		// 作業用一時フォルダ内のcsv、zipファイルを削除
		unlink($tmp_dir.DS.$csv_name);
		unlink($tmp_dir.DS.$files_name);
		unlink($tmp_dir.DS.$exp_name);
	}

	/**
	 * テスト問題情報のインポート
	 */
	public function admin_import($content_id)
	{
		if(Configure::read('demo_mode'))
			return;

		// コンテンツの情報を取得
		$content = $this->fetchTable('Content')->get($content_id);
		$course_id = $content['Content']['course_id'];

		$err_msg = '';
		
		if($this->request->is(['post', 'put']))
		{
			// 制限時間を120秒に設定
			set_time_limit(120);
			
			// 画面情報を受け取る
			$csvfile = $this->request->data['ContentsQuestion']['csvfile'];
			$zipfile = $this->request->data['ContentsQuestion']['zipfile'];
			$import_mode = $this->request->data['ContentsQuestion']['import_mode'];
			
			// インポートファイルが指定されていない場合、エラーメッセージを表示
			if($csvfile['error'] != 0)
			{
				$this->Flash->error(__('インポートファイルが指定されていません'));
				$this->set(compact('err_msg'));
				return;
			}
			
			// CSVファイルの読み込み
			$csv = Utils::getCsvData($csvfile['tmp_name']);
			
			// 仮データソースを定義
			$ds = $this->ContentsQuestion->getDataSource();
			$ds->begin();
			
			try
			{
				$is_error = false;
				$err_msg = '';
				$add_files = [];
				$line_index = 0;
				// 
				//========== CSVファイル ====================================//
				// CSVファイルから指定コースのコンテンツに追加/置換登録
				list($is_error, $err_msg, $add_files) = $this->ContentsQuestion->importQuestion($course_id, $content_id, $csv, $line_index, $import_mode);
				//========== ZIPファイル ====================================//
				// コンテンツ登録でエラーがなく、かつ、インポートファイル(ZIPファイル)が指定されていれば、
				// ZIPされているファイルをコースフォルダに格納する
				if (($zipfile['error'] == 0) && (!$is_error))
				{
					list($is_error, $err_msg) = $this->fetchTable('Course')->importFiles($course_id, $zipfile, $add_files);
				}
								
				//------------------------------//
				//	処理結果確認				//
				//------------------------------//
				if($is_error)
				{
					$ds->rollback();
					$this->Flash->error(__('インポートに失敗しました'));
				}
				else
				{
					// 置き換えの場合、インポートしたコース関連の学習履歴を消去、余った古いコンテンツを削除
					if ($import_mode == 'r')
					{
						$this->request->allowMethod('post', 'delete');
						$this->ContentsQuestion->deleteRecordImport($content_id);
						$this->ContentsQuestion->deleteLogicalDelQuestion($content_id);
					}
					// 問題情報を取得
					$this->request->allowMethod('post', 'delete');
					$this->ContentsQuestion->deleteRecordImport($content_id);

					$ds->commit();
					$this->Flash->success(__('インポートが完了しました'));
					return $this->redirect(['action' => 'index', $content_id]);
				}
			}
			catch(Exception $e)
			{
				$ds->rollback();
				$this->Flash->error(__('インポートに失敗しました'));
			}
		}
		
		$this->set(compact('err_msg'));
		$this->set('content_id', $content_id);
	}

	// リッチテキスト内のコースIDをインポート先のコースIDに変更し、インポートファイルを抽出する
	private function check_import_file($rich_text, $files_list, $course_id)
	{
		if(strstr($rich_text, 'file_image'))
		{
			if(preg_match_all('/file_image\/(.+?)\/\d+\"/', $rich_text, $rich_images) > 0)
			{
				foreach($rich_images[1] as $image)
				{
					array_push($files_list, $image);
				}
			}
			$rich_text = preg_replace_callback(
					'/(file_image\/.+?\/)\d+(\")/',
					function($m) use ($course_id) {
						return $m[1] . $course_id . $m[2];
					},
					$rich_text
				);
		}
		return [$rich_text, $files_list];

	}

	/**
	 * テスト問題のコピー
	 * @param int $content_id コピーするテスト問題のコンテンツのID
	 * @param int $contentsQuestion_id コピーするテスト問題のID
	 */
	public function admin_copy($content_id, $contentsQuestion_id)
	{
		$this->request->allowMethod('post');
		
		$contentsQuestion = $this->fetchTable('ContentsQuestion')->get($contentsQuestion_id);
		
		$row = $this->fetchTable('ContentsQuestion')->find()
			->where(['content_id' => $content_id])
			->select('MAX(ContentsQuestion.sort_no) as max_sort_no')
			->first();
		$new_sort_no = $row[0]['max_sort_no'] + 1;

		$contentsQuestion['ContentsQuestion']['title'] .= 'の複製';
		$contentsQuestion['ContentsQuestion']['id']			= null;
		$contentsQuestion['ContentsQuestion']['created']	= null;
		$contentsQuestion['ContentsQuestion']['modified']	= null;
		$contentsQuestion['ContentsQuestion']['sort_no']	= $new_sort_no;
		
		$this->fetchTable('ContentsQuestion')->validate = null;
		
		$this->fetchTable('ContentsQuestion')->create($contentsQuestion);
		$this->fetchTable('ContentsQuestion')->save();
			
		$this->Flash->success(__('問題の複製が完了しました。'));
		
		return $this->redirect(['action' => 'index',$content_id]);
	}

}
