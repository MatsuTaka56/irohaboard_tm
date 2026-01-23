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
 * Contents Controller
 * https://book.cakephp.org/2/ja/controllers.html
 */
class ContentsController extends AppController
{
	/**
	 * 使用するコンポーネント
	 * https://book.cakephp.org/2/ja/core-libraries/toc-components.html
	 */
	public $components = [
		'Security' => [
			'validatePost' => false,
			'csrfUseOnce' => false,
			'csrfExpires' => '+3 hours',
			'csrfLimit' => 10000,
		],
	];

	/**
	 * 学習コンテンツ一覧を表示
	 * @param int $course_id コースID
	 * @param int $user_id 学習履歴を表示するユーザのID
	 */
	public function index($course_id, $user_id = null)
	{
		$course_id = intval($course_id);
		$user_id = ($user_id != null) ? intval($user_id) : null;
		
		// コースの情報を取得
		$course = $this->fetchTable('Course')->get($course_id);
		
		// ロールを取得
		$role = $this->readAuthUser('role');
		
		// 管理者かつ、学習履歴表示モードの場合、
		if($this->isAdminPage() && $this->isRecordPage())
		{
			$contents = $this->Content->getContentRecord($user_id, $course_id, $role);
		}
		else
		{
			// コースの閲覧権限の確認
			if(!$this->fetchTable('Course')->hasRight($this->readAuthUser('id'), $course_id))
			{
				throw new NotFoundException(__('Invalid access'));
			}
			
			$contents = $this->Content->getContentRecord($this->readAuthUser('id'), $course_id, $role);
		}
		
		// アップロードファイル参照用
		$this->writeCookie('LoginStatus', 'logined');
		
		$this->set(compact('course', 'contents'));
	}

	/**
	 * コンテンツの表示
	 * @param int $content_id 表示するコンテンツのID
	 */
	public function view($content_id)
	{
		$content_id = intval($content_id);
		
		if(!$this->Content->exists($content_id))
		{
			throw new NotFoundException(__('Invalid content'));
		}

		// ヘッダー、フッターを非表示
		$this->layout = '';

		$content = $this->Content->get($content_id);
		
		// コンテンツの閲覧権限の確認
		if(!$this->fetchTable('Course')->hasRight($this->readAuthUser('id'), $content['Content']['course_id']))
		{
			throw new NotFoundException(__('Invalid access'));
		}
		
		// 管理者以外の場合、非公開コンテンツへのアクセスを禁止
		if($this->readAuthUser('role') != 'admin' && $content['Content']['status'] != 1)
		{
			throw new NotFoundException(__('Invalid access'));
		}

		// 前後ページの確認
		$content['Content']['prev_page'] = 0;
		$content['Content']['next_page'] = 0;
		$next_content = $this->fetchTable('Content')->find()
					->where(['Content.sort_no < ' => $content['Content']['sort_no'], 
							'Content.course_id' => $content['Content']['course_id'],
							'NOT' => ['Content.kind IN' => ['test','file','label']],
							'Content.status' => 1])
					->order(['Content.sort_no' => 'DESC'])
					->first();
		if($next_content != null)
		{
			$content['Content']['prev_page'] = $next_content['Content']['id'];
		}
		$next_content = $this->fetchTable('Content')->find()
					->where(['Content.sort_no > ' => $content['Content']['sort_no'], 
							'Content.course_id' => $content['Content']['course_id'],
							'NOT' => ['Content.kind IN' => ['test','file','label']],
							'Content.status' => 1])
					->order(['Content.sort_no' => 'ASC'])
					->first();
		if($next_content != null)
		{
			$content['Content']['next_page'] = $next_content['Content']['id'];
		}

		$content['Content']['mode'] = $content['Content']['wrong_mode'];
		if($content['Content']['mode'] === null) $content['Content']['mode']=0;

		$this->set(compact('content'));
	}

	/**
	 * コンテンツ一覧の表示
	 *
	 * @param int $course_id コースID
	 */
	public function admin_index($course_id)
	{
		$course_id = intval($course_id);
		
		$this->Content->recursive = 0;

		// コースの情報を取得
		$course = $this->Content->Course->get($course_id);

		$contents = $this->Content->find()
			->where(['Content.course_id' => $course_id])
			->order('Content.sort_no asc')
			->all();

		// コース情報を取得
		$course = $this->Content->Course->get($course_id);
		
		$this->set(compact('contents', 'course'));
	}

	/**
	 * コンテンツの追加
	 *
	 * @param int $course_id コースID
	 */
	public function admin_add($course_id)
	{
		$this->admin_edit($course_id);
		$this->render('admin_edit');
	}

	/**
	 * コンテンツの編集
	 *
	 * @param int $course_id 所属するコースのID
	 * @param int $content_id 編集するコンテンツのID (指定しない場合、追加)
	 */
	public function admin_edit($course_id, $content_id = null)
	{
		$course_id = intval($course_id);
		
		if($this->isEditPage() && !$this->Content->exists($content_id))
		{
			throw new NotFoundException(__('Invalid content'));
		}
		
		if($this->request->is(['post', 'put']))
		{
			if(Configure::read('demo_mode'))
				return;
			
			// 新規追加の場合、コンテンツの作成者と所属コースを指定
			if(!$this->isEditPage())
			{
				$this->request->data['Content']['user_id']	 = $this->readAuthUser('id');
				$this->request->data['Content']['course_id'] = $course_id;
				$this->request->data['Content']['sort_no']	 = $this->Content->getNextSortNo($course_id);
			}
			
			if(in_array($this->request->data['Content']['kind'],['html', 'url', 'movie', 'pict']))
			{
				$this->request->data['Content']['wrong_mode'] = $this->request->data['Content']['mode'];
			}

			if($this->Content->save($this->request->data))
			{
				$this->Flash->success(__('コンテンツが保存されました'));
				return $this->redirect(['action' => 'index', $course_id]);
			}
			else
			{
				$this->Flash->error(__('The content could not be saved. Please, try again.'));
			}
		}
		else
		{
			$this->request->data = $this->Content->get($content_id);
			$this->request->data['Content']['mode'] = $this->request->data['Content']['wrong_mode'];
		}
		
		// コース情報を取得
		$course = $this->Content->Course->get($course_id);
		$courses = $this->Content->Course->find('list');
		
		$this->set(compact('course', 'courses'));
	}

	/**
	 * コンテンツの削除
	 *
	 * @param int $content_id 削除するコンテンツのID
	 */
	public function admin_delete($content_id)
	{
		if(Configure::read('demo_mode'))
			return;
		
		$this->Content->id = $content_id;
		
		if(!$this->Content->exists())
		{
			throw new NotFoundException(__('Invalid content'));
		}
		
		// コンテンツ情報を取得
		$content = $this->Content->get($content_id);
		
		$this->request->allowMethod('post', 'delete');
		
		if($this->Content->delete())
		{
			// コンテンツに紐づくテスト問題も削除
			$this->fetchTable('ContentsQuestion')->deleteAll(['ContentsQuestion.content_id' => $content_id], false);
			$this->request->allowMethod('post', 'delete');
			$this->Flash->success(__('コンテンツが削除されました'));
		}
		else
		{
			$this->Flash->error(__('The content could not be deleted. Please, try again.'));
		}
		
		return $this->redirect(['action' => 'index', $content['Course']['id']]);
	}

	/**
	 * プレビュー用に入力内容をセッションに保存
	 */
	public function admin_preview()
	{
		$this->autoRender = FALSE;
		
		if($this->request->is('ajax'))
		{
			$data = [
				'Content' => [
					'id'	 => 0,
					'title'  => $this->getData('content_title'),
					'kind'	 => $this->getData('content_kind'),
					'url'	 => $this->getData('content_url'),
					'file_name'	 => $this->getData('content_file_name'),
					'body'	 => $this->getData('content_body'),
					'mode'	 => $this->getData('content_mode'),
				],
				'Course' => [
					'id'	 => 0,
				]
			];
			$data['Content']['prev_page'] = 1;
			$data['Content']['next_page'] = 1;
			
			$this->writeSession("Iroha.preview_content", $data);
		}
	}
	
	/**
	 * 動画ファイルのプレビュー
	 * @param int $file_name ファイル名
	 */
	public function admin_preview_movie($file_name, $course_id)
	{
		// ファイルが指定されていない場合
		if(!$file_name)
		{
			throw new NotFoundException(__('Invalid content'));
		}
		// コースの情報を取得
		$course = $this->fetchTable('Course')->get($course_id);
		$course_name = $course['Course']['title'];

		$safe_file_name = basename($file_name); // セキュリティ対策
		$file_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$course_id.DS.$safe_file_name;
		
		$upload_extensions = (array)Configure::read('upload_movie_extensions');
		$extension = "." . pathinfo($safe_file_name, PATHINFO_EXTENSION);

		// 動画ファイル以外が指定されている場合
		if(!in_array($extension, $upload_extensions))
		{
			throw new NotFoundException(__('Invalid content'));
		}

		// ファイル名が英数字、ピリオド、ハイフン、アンダースコア以外の場合
		/*
		if(!preg_match('/^[a-zA-Z0-9\.\-_]+$/', $safe_file_name))
		{
			throw new NotFoundException(__('Invalid content'));
		}
		*/

		// ファイルが存在しない場合
		if(!file_exists($file_path))
		{
			$file_path = WWW_ROOT.DS.'uploads'.DS.$safe_file_name;
			
			if(!file_exists($file_path))
			{
				throw new NotFoundException(__('File not found'));
			}
		}
		
		$this->response->file($file_path, ['download' => false, 'name' => $safe_file_name]);
		return $this->response;
	}
	
	/**
	 * 画像ファイルのプレビュー
	 * @param int $file_name ファイル名
	 */
	public function admin_preview_pict($file_name, $course_id)
	{
		// ファイルが指定されていない場合
		if(!$file_name)
		{
			throw new NotFoundException(__('Invalid content'));
		}
		// コースの情報を取得
		$course = $this->fetchTable('Course')->get($course_id);
		$course_name = $course['Course']['title'];

		$safe_file_name = basename($file_name); // セキュリティ対策
		$file_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$course_id.DS.$safe_file_name;
		
		$upload_extensions = (array)Configure::read('upload_image_extensions');
		$extension = "." . pathinfo($safe_file_name, PATHINFO_EXTENSION);

		// 画像ファイル以外が指定されている場合
		if(!in_array($extension, $upload_extensions))
		{
			throw new NotFoundException(__('Invalid content'));
		}

		// ファイル名が英数字、ピリオド、ハイフン、アンダースコア以外の場合
		/*
		if(!preg_match('/^[a-zA-Z0-9\.\-_]+$/', $safe_file_name))
		{
			throw new NotFoundException(__('Invalid content'));
		}
		*/

		// ファイルが存在しない場合
		if(!file_exists($file_path))
		{
			$file_path = WWW_ROOT.DS.'uploads'.DS.$safe_file_name;
			
			if(!file_exists($file_path))
			{
				throw new NotFoundException(__('File not found'));
			}
		}
		
		$this->response->file($file_path, ['download' => false, 'name' => $safe_file_name]);
		return $this->response;
	}

	/**
	 * セッションに保存された情報を元にプレビュー
	 */
	public function preview()
	{
		// ヘッダー、フッターを非表示
		$this->layout = '';
		$this->set('content', $this->readSession('Iroha.preview_content'));
		$this->render('view');
	}

	/**
	 * ファイル（配布資料、動画、画像）のアップロード
	 *
	 * @param int $file_type ファイルの種類
	 * @param int $course_id コースID（ファイルの格納用）
	 */
	public function admin_upload($file_type, $course_id)
	{
		header('X-Frame-Options: SAMEORIGIN');
		
		App::import ('Vendor', 'FileUpload');

		$fileUpload = new FileUpload();

		$mode = '';
		$file_url = '';
		
		// ファイルの種類によって、アップロード可能な拡張子とファイルサイズを指定
		switch($file_type)
		{
			case 'file' :
				$upload_extensions = (array)Configure::read('upload_extensions');
				$upload_maxsize = Configure::read('upload_maxsize');
				break;
			case 'image' :
			case 'pict' :
				$upload_extensions = (array)Configure::read('upload_image_extensions');
				$upload_maxsize = Configure::read('upload_image_maxsize');
				break;
			case 'movie' :
				$upload_extensions = (array)Configure::read('upload_movie_extensions');
				$upload_maxsize = Configure::read('upload_movie_maxsize');
				break;
			default :
				throw new NotFoundException(__('Invalid access'));
		}
		
		// php.ini の upload_max_filesize, post_max_size の値を確認（互換性維持のためメソッドが存在する場合のみ）
		if(method_exists($fileUpload, 'getBytes'))
		{
			$upload_max_filesize = $fileUpload->getBytes(ini_get('upload_max_filesize'));
			$post_max_size		 = $fileUpload->getBytes(ini_get('post_max_size'));
			
			// upload_max_filesize が設定サイズより小さい場合、upload_max_filesize を優先する
			if($upload_max_filesize < $upload_maxsize)
				$upload_maxsize	= $upload_max_filesize;
			
			// post_max_size が設定サイズより小さい場合、post_max_size を優先する
			if($post_max_size < $upload_maxsize)
				$upload_maxsize	= $post_max_size;
		}
		
		$fileUpload->setExtension($upload_extensions);
		$fileUpload->setMaxSize($upload_maxsize);
		
		$original_file_name = '';
		
		if($this->request->is(['post', 'put']))
		{
			if(Configure::read('demo_mode'))
				return;
			// コースの情報を取得
			$course = $this->fetchTable('Course')->get($course_id);
			$course_name = $course['Course']['title'];

			//filesフォルダの存在チェック
			$dirPath = ROOT.DS.APP_DIR.DS.'files';
			if(!is_dir($dirPath))
			{
				// なければ作成する
				if(!mkdir($dirPath, 0755)){
					//作成に失敗した時の処理
					$this->Flash->error('保存先のフォルダ(files)の作成に失敗しました');
					$mode = 'error';
					return;
				}
			}
			// アップロード用のコースフォルダの存在チェック
			$dirPath = $dirPath.DS.'course_'.$course_id;
			if (!is_dir($dirPath))
			{
				// なければ作成する
				if(!mkdir($dirPath, 0777)){
					//作成に失敗した時の処理
					$this->Flash->error('保存先のフォルダ(コースフォルダ)の作成に失敗しました');
					$mode = 'error';
					return;
				}
			}

			preg_match('/^(.+)\.(.+)$/', $this->getData('Content')['file']['name'], $split_file_name);
			$original_file_name = $split_file_name[1].'.'.strtolower($split_file_name[2]);

			$file_name = $dirPath.DS.$original_file_name;			//	ファイルのパス
			$file_url = $original_file_name;
			$mode = 'complete';
			// アップロードファイルの存在をチェック
			if (!file_exists($file_name)) {
				// あれば、ファイルパスだけを取得して終了
				// なければ、ファイルアップロード処理を実行
				// ファイルの読み込み
				$fileUpload->readFile( $this->getData('Content')['file'] );

				$error_code = 0;
				
				// エラーチェック（互換性維持のためメソッドが存在する場合のみ）
				if(method_exists($fileUpload, 'checkFile'))
					$error_code = $fileUpload->checkFile();
				
				if($error_code > 0)
				{
					$mode = 'error';
					
					switch($error_code)
					{
						case 1001 : // 拡張子エラー
							$this->Flash->error('アップロードされたファイルの形式は許可されていません');
							break;
						case 1002 : // ファイルサイズが0
						case 1003 : // ファイルサイズオバー
							$size = $this->getData('Content')['file']['size'];
							$this->Flash->error('アップロードされたファイルのサイズ（'.$size.'）は許可されていません');
							break;
						default :
							$this->Flash->error('アップロード中にエラーが発生しました ('.$error_code.')');
					}
				}
				else
				{
					$result = $fileUpload->saveFile( $file_name );		//	ファイルの保存
					if($result)											//	結果によってメッセージを設定
					{
						$this->Flash->success('ファイルのアップロードが完了いたしました');
					}
					else
					{
						$this->Flash->error('ファイルのアップロードに失敗しました');
						$mode = 'error';
					}
				}
			}
		}

		$file_name = $original_file_name;
		$upload_extensions = join(', ', $upload_extensions);
		
		$this->set(compact('mode', 'file_url', 'file_name', 'upload_extensions', 'upload_maxsize'));
	}
	
	/**
	 * リッチテキストエディタ(Summernote) から送信された画像を保存
	 *
	 * @return string アップロードした画像のURL(JSON形式)
	 */
	public function admin_upload_image($course_id=0)
	{
		$this->autoRender = FALSE;
		
		if($this->request->is('ajax'))
		{
			App::import ('Vendor', 'FileUpload');
			$fileUpload = new FileUpload();
			
			// アップロード可能な拡張子とファイルサイズを指定
			$upload_extensions = (array)Configure::read('upload_image_extensions');
			$upload_maxsize = Configure::read('upload_image_maxsize');
			
			$fileUpload->setExtension($upload_extensions);
			$fileUpload->setMaxSize($upload_maxsize);
			$fileUpload->readFile( $this->getParam('form')['file'] );								//	ファイルの読み込み
						
			// ファイル名：オリジナルファイル名を設定
			preg_match('/^(.+)\.(.+)$/', $fileUpload->getFileName(), $org_file_name);
			$new_name = $org_file_name[1].'.'.strtolower($org_file_name[2]);

			$dirPath = ROOT.DS.APP_DIR.DS.'files';
			
			if(!is_dir($dirPath))
			{
				if(!mkdir($dirPath, 0755)){
					//作成に失敗した時の処理
					$this->Flash->error('保存先のフォルダ(files)の作成に失敗しました');
					$mode = 'error';
				}
			}
			if($course_id != 0)
			{
				// コースの情報を取得
				$course = $this->fetchTable('Course')->get($course_id);
				$course_name = $course['Course']['title'];
				// アップロード用のコースフォルダの存在チェック
				$dirPath = $dirPath.DS.'course_'.$course_id;
				if (!is_dir($dirPath))
				{
					// なければ作成する
					if(!mkdir($dirPath, 0777)){
						//作成に失敗した時の処理
						$this->Flash->error('保存先のフォルダ(コースフォルダ)の作成に失敗しました');
						$mode = 'error';
					}
				}
			}

			$file_name = $dirPath.DS.$new_name;														//	ファイルのパス
			$file_url = $this->webroot.'contents/file_image/'.$new_name.'/'.$course_id;							//	ファイル名

			$result = $fileUpload->saveFile( $file_name );											//	ファイルの保存
			
			// 画像のURLをJSON形式で出力
			$response = $result ? [$file_url] : [false];
			echo json_encode($response);
		}
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
			$this->Content->setOrder($this->data['id_list']);
			return 'OK';
		}
	}

	/**
	 * 学習履歴の表示
	 * @param int $course_id
	 * @param int $user_id
	 */
	public function admin_record($course_id, $user_id)
	{
		$this->index($course_id, $user_id);
		$this->render('index');
	}

	/**
	 * コンテンツのコピー
	 * @param int $course_id コピー先のコースのID
	 * @param int $content_id コピーするコンテンツのID
	 */
	public function admin_copy($course_id, $content_id)
	{
		$this->request->allowMethod('post');
		
		// コンテンツのコピー
		$data = $this->Content->get($content_id);
		$row  = $this->Content->find()
			->select(['MAX(Content.id) as max_id'])
			->first();
		
		$new_content_id = $row[0]['max_id'] + 1;
		
		$data['Content']['id'] = $new_content_id;
		$data['Content']['created'] = null;
		$data['Content']['modified'] = null;
		$data['Content']['status'] = 0;
		$data['Content']['title'] .= 'の複製';
		
		$this->Content->save($data);
		
		// テスト問題のコピー
		$contentsQuestions = $this->fetchTable('ContentsQuestion')->find()
			->where(['content_id' => $content_id])
			->order('ContentsQuestion.sort_no asc')
			->all();
		
		$sort_no = 1;
		
		foreach($contentsQuestions as $contentsQuestion)
		{
			$row = $this->fetchTable('ContentsQuestion')->find()
				->select('MAX(ContentsQuestion.id) as max_id')
				->first();
			
			$new_question_id = $row[0]['max_id'] + 1;
			
			$contentsQuestion['ContentsQuestion']['id']			= null;
			$contentsQuestion['ContentsQuestion']['created']	= null;
			$contentsQuestion['ContentsQuestion']['modified']	= null;
			$contentsQuestion['ContentsQuestion']['content_id']	= $new_content_id;
			$contentsQuestion['ContentsQuestion']['sort_no']	= $sort_no;
			
			$this->fetchTable('ContentsQuestion')->validate = null;
			
			$this->fetchTable('ContentsQuestion')->create($contentsQuestion);
			$this->fetchTable('ContentsQuestion')->save();
			
			$sort_no++;
		}
		
		return $this->redirect(['action' => 'index',$course_id]);
	}

	/**
	 * ファイルのダウンロード
	 * @param int $content_id コンテンツID
	 */
	public function file_download($content_id)
	{
		$content_id = intval($content_id);
		
		// コンテンツが存在しない場合
		if(!$this->Content->exists($content_id))
		{
			throw new NotFoundException(__('Invalid content'));
		}

		$content = $this->Content->get($content_id);
		
		// コンテンツの閲覧権限の確認
		if(!$this->fetchTable('Course')->hasRight($this->readAuthUser('id'), $content['Content']['course_id']))
		{
			throw new NotFoundException(__('Invalid access'));
		}

		// 管理者以外の場合、非公開コンテンツへのアクセスを禁止
		if($this->readAuthUser('role') != 'admin' && $content['Content']['status'] != 1)
		{
			throw new NotFoundException(__('Invalid access'));
		}
		
		// 配布資料以外の場合、アクセスを禁止
		if($content['Content']['kind'] != 'file')
		{
			throw new NotFoundException(__('Invalid content'));
		}
		
		// ファイルのパスを取得（公開ディレクトリの外）
		$safe_file_name = basename($content['Content']['file_name']); // セキュリティ対策
		$file_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$content['Course']['id'].DS.$safe_file_name;
		
		// ファイルが存在しない場合
		if(!file_exists($file_path))
		{
			$file_path = WWW_ROOT.DS.'uploads'.DS.$safe_file_name;

			if(!file_exists($file_path))
			{
				throw new NotFoundException(__('File not found'));
			}
		}
		
		// ファイルのダウンロード
		$this->response->file($file_path, [
			'download' => true,
			'name' => $content['Content']['file_name']
		]);
		
		return $this->response;
	}

	/**
	 * 動画ファイルの表示
	 * @param int $file_name ファイル名
	 */
	public function file_movie($content_id)
	{
		$content_id = intval($content_id);

		// コンテンツが存在しない場合
		if(!$this->Content->exists($content_id))
		{
			throw new NotFoundException(__('Invalid content'));
		}
		
		$content = $this->Content->get($content_id);
		
		// コンテンツの閲覧権限の確認
		if(!$this->fetchTable('Course')->hasRight($this->readAuthUser('id'), $content['Content']['course_id']))
		{
			throw new NotFoundException(__('Invalid access'));
		}

		// 管理者以外の場合、非公開コンテンツへのアクセスを禁止
		if($this->readAuthUser('role') != 'admin' && $content['Content']['status'] != 1)
		{
			throw new NotFoundException(__('Invalid access'));
		}

		// 動画コンテンツ以外の場合、アクセスを禁止
		if($content['Content']['kind'] != 'movie')
		{
			throw new NotFoundException(__('Invalid content'));
		}

		$safe_file_name = basename($content['Content']['file_name']); // セキュリティ対策
		$file_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$content['Course']['id'].DS.$safe_file_name;
		
		$upload_extensions = (array)Configure::read('upload_movie_extensions');
		$extension = "." . pathinfo($safe_file_name, PATHINFO_EXTENSION);

		// 動画ファイル以外が指定されている場合
		if(!in_array($extension, $upload_extensions))
		{
			throw new NotFoundException(__('Invalid content'));
		}

		// ファイルが存在しない場合
		if(!file_exists($file_path))
		{
			$file_path = WWW_ROOT.DS.'uploads'.DS.$safe_file_name;
			
			if(!file_exists($file_path))
			{
				throw new NotFoundException(__('File not found'));
			}
		}
		
		$this->response->file($file_path, ['download' => false, 'name' => $safe_file_name]);
		return $this->response;
	}

	/**
	 * 画像ファイルの表示
	 * @param int $file_name ファイル名
	 */
	public function file_pict($content_id)
	{
		$content_id = intval($content_id);

		// コンテンツが存在しない場合
		if(!$this->Content->exists($content_id))
		{
			throw new NotFoundException(__('Invalid content'));
		}
		
		$content = $this->Content->get($content_id);
		
		// コンテンツの閲覧権限の確認
		if(!$this->fetchTable('Course')->hasRight($this->readAuthUser('id'), $content['Content']['course_id']))
		{
			throw new NotFoundException(__('Invalid access'));
		}

		// 管理者以外の場合、非公開コンテンツへのアクセスを禁止
		if($this->readAuthUser('role') != 'admin' && $content['Content']['status'] != 1)
		{
			throw new NotFoundException(__('Invalid access'));
		}

		// 画像コンテンツ以外の場合、アクセスを禁止
		if($content['Content']['kind'] != 'pict')
		{
			throw new NotFoundException(__('Invalid content'));
		}

		$safe_file_name = basename($content['Content']['file_name']); // セキュリティ対策
		$file_path = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$content['Course']['id'].DS.$safe_file_name;
		
		$upload_extensions = (array)Configure::read('upload_image_extensions');
		$extension = "." . pathinfo($safe_file_name, PATHINFO_EXTENSION);

		// 画像ファイル以外が指定されている場合
		if(!in_array($extension, $upload_extensions))
		{
			throw new NotFoundException(__('Invalid content'));
		}

		// ファイルが存在しない場合
		if(!file_exists($file_path))
		{
			$file_path = WWW_ROOT.DS.'uploads'.DS.$safe_file_name;
			
			if(!file_exists($file_path))
			{
				throw new NotFoundException(__('File not found'));
			}
		}
		
		$this->response->file($file_path, ['download' => false, 'name' => $safe_file_name]);
		return $this->response;
	}

	/**
	 * 画像ファイルの表示
	 * @param int $file_name ファイル名
	 */
	public function file_image($file_name, $course_id=0)
	{
		// ファイルが指定されていない場合
		if(!$file_name)
		{
			throw new NotFoundException(__('Invalid content'));
		}

		// ファイル名を正規化
		$file_name = mb_convert_encoding($file_name, 'UTF-8', 'UTF-8');
		
		// 許可する文字パターンを定義
		/*
		if(!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $file_name))
		{
			throw new NotFoundException(__('Invalid filename'));
		}
		*/
		
		// ファイル名の長さを制限
		if(strlen($file_name) > 255)
		{
			throw new NotFoundException(__('Invalid filename'));
		}
		
		// ドットで始まるファイル名を禁止
		if(strpos($file_name, '.') === 0)
		{
			throw new NotFoundException(__('Invalid filename'));
		}

		// 許可する拡張子をホワイトリストで管理
		$upload_extensions = (array)Configure::read('upload_image_extensions');
		$extension = "." . pathinfo($file_name, PATHINFO_EXTENSION);

		// 許可する拡張子以外が指定されている場合
		if(!in_array($extension, $upload_extensions))
		{
			throw new NotFoundException(__('Invalid content'));
		}
		
		$file_path = ROOT.DS.APP_DIR.DS.'files'.DS;
		if($course_id !=0)
		{
			// コースの情報を取得
			$course = $this->fetchTable('Course')->get($course_id);
			$course_name = $course['Course']['title'];
			$file_path = $file_path.'course_'.$course_id.DS;
		}

		$safe_file_name = basename($file_name); // セキュリティ対策
		$file_path = $file_path.$safe_file_name;
		
		// ファイル名がディレクトリを示している場合
		if(is_dir($file_path))
		{
			throw new NotFoundException(__('Invalid content'));
		}

		// ファイルが存在しない場合
		if(!file_exists($file_path))
		{
			$file_path = WWW_ROOT.DS.'uploads'.DS.$safe_file_name;
			
			if(!file_exists($file_path))
			{
				throw new NotFoundException(__('File not found'));
			}
		}
		
		$this->response->file($file_path, ['download' => false, 'name' => $safe_file_name]);
		return $this->response;
	}


	/**
	 * コンテンツ情報のエクスポート
	 */
	public function admin_export($course_id)
	{
		// コースの情報を取得
		$course = $this->fetchTable('Course')->get($course_id);
		$course_name = $course['Course']['title'];
		
		$this->autoRender = false;
		Configure::write('debug', 0);

		//ファイルエクスポート用のzipオブジェクトを定義
		$zip_obj = new ZipArchive;
		$tmp_dir = ROOT.DS.APP_DIR.DS.'files'.'/tmp';
		$files_name = $course_name.'_files_'.date('Ymd').'.zip';
		$files = array();
		$csv_name = $course_name.'_csv_'.date('Ymd').'.csv';
		$exp_name = $course_name.'_exp_'.date('Ymd').'.zip';

		if(!is_dir($tmp_dir))
		{
			mkdir($tmp_dir, 0755);
		}

		$fp = fopen($tmp_dir.DS.$csv_name,'w');
		
		$header_list = Configure::read('export_content_header');
		//------------------------------//
		//	ヘッダー行の作成			//
		//------------------------------//
		$header = array();
		foreach ($header_list as $key => $val)
		{
			$header[] = __($val.' ');
		}
		
		// ヘッダー行をCSV出力
		mb_convert_variables('SJIS-win', 'UTF-8', $header);
		fputcsv($fp, $header);
		
		//------------------------------//
		//	コンテンツ情報の取得			//
		//------------------------------//
		
		// パフォーマンスの改善の為、一定件数に分割してデータを取得
		$limit      = 500;
		$content_count = $this->Content->find()
			->where(['course_id' => $course_id])
			->count();	// コンテンツ数を取得
		$page_size  = ceil($content_count / $limit);	// ページ数（ユーザ数 / ページ単位）
		
		// ページ単位でコンテンツを取得
		for($page=1; $page <= $page_size; $page++)
		{
			// コンテンツ情報を取得
			$this->Content->recursive = 1;
			$rows = $this->Content->find()
				->where(['course_id' => $course_id])
				->limit($limit)
				->page($page)
				->order('Content.sort_no asc')
				->all();
			
			foreach($rows as $row)
			{
				//------------------------------//
				//	出力するデータを作成		//
				//------------------------------//
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
		
		// 画像、動画、リッチテキストのimageファイルがあれば、zipにまとめる
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
		$result = $zip_obj->open($tmp_dir.DS.$exp_name, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE);
		if(!$result){
			$this->Flash->error(__('ZIPファイルがオープンできません'));
			$this->set(compact('err_msg'));
			return;
		}
		
		$zip_obj->addFile($tmp_dir.DS.$csv_name, $csv_name);
		if(count($files) != 0)
		{
			$zip_obj->addFile($tmp_dir.DS.$files_name, $files_name);
		}

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

		//tmpフォルダ内のcsv、zipファイルを削除
		unlink($tmp_dir.DS.$csv_name);
		unlink($tmp_dir.DS.$files_name);
		unlink($tmp_dir.DS.$exp_name);
	}

	/**
	 * コンテンツ情報のインポート
	 */
	public function admin_import($course_id)
	{
		if(Configure::read('demo_mode'))
			return;

		$err_msg = '';
		$add_files = [];
		
		if($this->request->is(['post', 'put']))
		{
		//========== CSVファイル ====================================//
			//------------------------------//
			//	列番号の定義				//
			//------------------------------//

			$header_list = Configure::read('import_content_header');
			$col_no = 0;
			foreach ($header_list as $key => $val)
			{
				$col_list[$val] = $col_no;
				$col_no++;
			}

			//------------------------------//
			//	CSVファイルの読み込み		//
			//------------------------------//
			// 制限時間を120秒に設定
			set_time_limit(120);
			
			$csvfile = $this->request->data['Content']['csvfile'];
			
			// インポートファイルが指定されていない場合、エラーメッセージを表示
			if($csvfile['error'] != 0)
			{
				$this->Flash->error(__('インポートファイルが指定されていません'));
				$this->set(compact('err_msg'));
				return;
			}
			
			// CSVファイルの読み込み
			$csv = Utils::getCsvData($csvfile['tmp_name']);
			
			$i = 0;
			
			$ds = $this->Content->getDataSource();
			$ds->begin();
			
			try
			{
				$is_error = false;

				// 該当コースのコンテンツに削除フラグを立てる
				$contents_course = $this->Content->find()
					->where(['Content.course_id' => $course_id])
					->all();
				foreach($contents_course as $content_dell)
				{
					$content_dell['Content']['deleted'] = date('Y-m-d H:i:s');	//削除日付の設定
					$content_dell['Content']['status'] = 0;						//非表示の設定
					if(!$this->Content->save($content_dell))
					{
						// 保存時にエラーが発生した場合、モデルからエラー情報を抽出
						$err_list = $this->User->validationErrors;
						foreach($err_list as $err)
						{
							$err_msg .= '<li>'.$i.'行目 : '.$err[0].'</li>';
						}
						$is_error = true;
					}
				}

				// 1行ごとにデータを登録
				$header_def = array_keys($header_list);
				foreach($csv as $row)
				{
					$i++;	//行カウンタ＋１
					
					if($i == 1)		//ヘッダ行（1行目）
					{
						// 列順序を確認する
						foreach($row as $index => $header_import)
						{
							if($index >= count($header_def)) break;
							if(trim($header_import) != $header_def[$index])
							{
								$is_error = true;
								$err_msg .= '<li>'.$i.'行目 : ヘッダ項目が一致しません</li>';
								break;
							}
						}
						if($is_error) break;	//ヘッダ行不良 => import処理中断
						continue;			// ヘッダ行正常 => 次の行へ
					}
					
					if(count($row) < count($header_def))	// ヘッダ項目数以下の行はスキップ
						continue;
					
					$is_new = false;
					$data = [];
					$data['Content'] = [];
					$this->Content->create();
					
					//------------------------------//
					//	コンテンツ情報の作成		  //
					//------------------------------//
					//既存コンテンツの確認
					$ex_data = $this->Content->find()
						->where(['Content.course_id' => $course_id])
						->where(['Content.deleted !=' => null])
						->first();
					
					// 指定したコースIDおよび削除日付有の既存コンテンツが存在しない場合、新規追加とする
					if(!$ex_data)
					{
						$data['Content']['created'] = date('Y-m-d H:i:s');
						$is_new = true;
					}
					else
					{
						$data['Content']['id'] = $ex_data['Content']['id'];
						$data['Content']['created'] = $ex_data['Content']['created'];
					}
					//importデータの指定の有無を確認しながらコンテンツデータを作成する
					//$data['Content']['id'] = $row[COL_id];
					$data['Content']['course_id'] = $course_id;
					$data['Content']['user_id'] = 1;

					$data['Content']['sort_no'] = $i - 1;

					if($row[$col_list['title']] === null) 
					{
						$is_error = true;
						$err_msg .= '<li>'.$i.'行目 : コンテンツ名が指定されていません。</li>';
						break;
					}
					$data['Content']['title'] = $row[$col_list['title']];

					if(Utils::getKeyByValue('content_kind', $row[$col_list['kind']]) === null) 
					{
						$is_error = true;
						$err_msg .= '<li>'.$i.'行目 : コンテンツ種別が指定されていません。</li>';
						break;
					}
					$data['Content']['kind'] = Utils::getKeyByValue('content_kind', $row[$col_list['kind']]);

					$data['Content']['file_name'] = "";
					if(in_array($data['Content']['kind'],['movie', 'file', 'pict']))
					{
						if($row[$col_list['file_name']] === null) 
						{
							$is_error = true;
							$err_msg .= '<li>'.$i.'行目 : ファイル名が指定されていません('.$data['Content']['kind'].')。</li>';
							break;
						}
						else
						{
							$data['Content']['file_name'] = $row[$col_list['file_name']];
							array_push($add_files, $data['Content']['file_name']);
						}
					}
						
					$data['Content']['url'] = "";
					if(in_array($data['Content']['kind'],['url']))
					{
						if($row[$col_list['url']] === null) 
						{
							$is_error = true;
							$err_msg .= '<li>'.$i.'行目 : URLが指定されていません('.$data['Content']['kind'].')。</li>';
							break;
						}
						else
						{
							$data['Content']['url'] = $row[$col_list['url']];
						}
					}
						
					if(in_array($data['Content']['kind'],['html']))
					{
						if($row[$col_list['body']] === null) 
						{
							$data['Content']['body'] = '<p><br></p>';
						}
						else
						{
							$data['Content']['body'] = $row[$col_list['body']];
							if(strstr($data['Content']['body'], 'file_image'))
							{
								if(preg_match_all('/file_image\/(.+?)\/\d+\"/', $data['Content']['body'], $rich_images) > 0)
								{
									foreach($rich_images[1] as $image)
									{
										array_push($add_files, $image);
									}
								}
								$data['Content']['body'] = preg_replace_callback(
										'/(file_image\/.+?\/)\d+(\")/',
										function($m) use ($course_id) {
											return $m[1] . $course_id . $m[2];
										},
										$row[$col_list['body']]
									);
							}
						}
					}

					$data['Content']['timelimit'] = "";
					$data['Content']['pass_rate'] = "";
					$data['Content']['question_count'] = "";
					$data['Content']['wrong_mode'] = 1;
					if($data['Content']['kind'] == 'test')
					{
						list($is_error, $err_msg) = $this -> test_num_check($row[$col_list['timelimit']], 1, 100, $i, 'テスト制限時間', $err_msg);
						if($is_error) break;
						$data['Content']['timelimit'] = $row[$col_list['timelimit']];

						list($is_error, $err_msg) = $this -> test_num_check($row[$col_list['pass_rate']], 1, 100, $i, '合格得点率 ', $err_msg);
						if($is_error) break;
						$data['Content']['pass_rate'] = $row[$col_list['pass_rate']];

						list($is_error, $err_msg) = $this -> test_num_check($row[$col_list['question_count']], 1, 100, $i, '出題数 ', $err_msg);
						if($is_error) break;
						$data['Content']['question_count'] = $row[$col_list['question_count']];

						list($is_error, $err_msg) = $this -> test_num_check($row[$col_list['wrong_mode']], 1, 3, $i, '不正解時の表示', $err_msg);
						if($is_error) break;
						if($row[$col_list['wrong_mode']] == null) 
						{
							$data['Content']['wrong_mode'] = 1;
						}
						else
						{
							$data['Content']['wrong_mode'] = $row[$col_list['wrong_mode']] - 1;
						}
					}

					if(in_array($data['Content']['kind'],['label', 'html', 'url', 'movie', 'pict']))
					{
						if($row[$col_list['mode']] != null) 
						{
							$data['Content']['wrong_mode'] = Utils::getKeyByValue('content_mode', $row[$col_list['mode']]);
						}
					}

					if(Utils::getKeyByValue('content_status', $row[$col_list['status']]) === null) 
					{
						$data['Content']['status'] = 1;
					}
					else
					{
						$data['Content']['status'] = Utils::getKeyByValue('content_status', $row[$col_list['status']]);
					}
					
					$data['Content']['opened'] = null;
					//$data['Content']['created'] = $row[COL_created];
					$data['Content']['modified'] = date('Y-m-d H:i:s');
					$data['Content']['deleted'] = null;
					$data['Content']['comment'] = Utils::issetOr($row[$col_list['comment']]);
					
					//------------------------------//
					//	保存						//
					//------------------------------//
					if(!$this->Content->save($data))
					{
						// 保存時にエラーが発生した場合、モデルからエラー情報を抽出
						$err_list = $this->User->validationErrors;
						
						foreach($err_list as $err)
						{
							$err_msg .= '<li>'.$i.'行目 : '.$err[0].'</li>';
						}
						
						$is_error = true;
					}
				}

				//========== ZIPファイル ====================================//
				if((count($add_files) >0) && !$is_error)
				{
					// 画像、動画、配布資料、イメージファイルの指定がある。
					//------------------------------//
					//	ZIPファイルの読み込み		 //
					//------------------------------//
					
					$zipfile = $this->request->data['Content']['zipfile'];
					
					// インポートファイル(ZIPファイル)が指定されていれば、
					// 内部の必要ファイルを抽出=>保存する
					if($zipfile['error'] == 0)
					{	// 指定あり
						// コース情報の取得
						$course = $this->fetchTable('Course')->get($course_id);
						$course_name = $course['Course']['title'];
						// 保存ディレクトリの設定
						$course_dir = ROOT.DS.APP_DIR.DS.'files'.DS.'course_'.$course_id.DS;
						$app_files_dir = ROOT.DS.APP_DIR.DS.'files'.DS;
						if (!file_exists($course_dir))
						{
							// 存在しなければ作成
							mkdir($course_dir, 0777, true);
						}

						// ZIPファイルの読み込み=>ファイル名抽出=>$add_filesに含まれるファイルの場合保存
						$zip = new ZipArchive();
						if ($zip->open($zipfile['tmp_name']) === TRUE)
						{
							// ZIP内のファイルを走査
							for ($i = 0; $i < $zip->numFiles; $i++)
							{
								$entry = $zip->getNameIndex($i);

								// ディレクトリはスキップ
								if (substr($entry, -1) === '/')
								{
									continue;
								}

								// ファイル名のみ取り出して判定
								$basename = basename($entry);

								if (in_array($basename, $add_files, true))
								{
									// 必要な動画、画像、配布資料ファイルだけ保存
									$content = $zip->getFromIndex($i);
									file_put_contents($course_dir.$basename, $content);
								}
							}

							$zip->close();
							// ZIPファイルを削除
							unlink($zipfile['tmp_name']);
						}
					}
					else
					{
						$is_error = true;
						$err_msg .= '<li>画像、動画、配布資料、イメージ用のZIPファイルが読むことができません。</li>';
					}
				}
				
				//------------------------------//
				//	エラー処理					//
				//------------------------------//
				if($is_error)
				{
					$ds->rollback();
					$this->Flash->error(__('インポートに失敗しました'));
				}
				else
				{
					$ds->commit();
					$this->Flash->success(__('インポートが完了しました'));
					return $this->redirect(['action' => 'index', $course_id]);
				}
			}
			catch(Exception $e)
			{
				$ds->rollback();
				$this->Flash->error(__('インポートに失敗しました'));
			}
		}
		
		$this->set(compact('err_msg'));
		$this->set('course_id', $course_id);
	}

	/**
	 * 数字チェック
	 */
	private function test_num_check($val, $min_num, $max_num, $i, $item, $err_msg)
	{
		if($val != null) 
		{
			if(!(preg_match("/^[0-9]+$/", $val)))
			{
				$err_msg .= '<li>'.$i.'行目 : '.$item.'が数字ではありません。</li>';
				return array(true, $err_msg);
			}
			if(($val < $min_num) || ($val > $max_num))
			{
				$err_msg .= '<li>'.$i.'行目 : '.$item.'が'.$min_num.'～'.$max_num.'ではありません。</li>';
				return array(true, $err_msg);
			}
		} 
		return array(false, $err_msg);
	}
}
