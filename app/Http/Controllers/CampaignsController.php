<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\CampaignsReported;
use App\Models\AdminSettings;
use App\Models\Campaigns;
use App\Models\Updates;
use App\Models\User;
use App\Helper;
use Carbon\Carbon;
use App\Models\Withdrawals;
use Image;
use Mail;

class CampaignsController extends Controller
{
	
	public function __construct( AdminSettings $settings, Request $request) {
		$this->settings = $settings::first();
		$this->request = $request;
	}
	
	protected function validator(array $data, $id = null) {
	 	
    	Validator::extend('ascii_only', function($attribute, $value, $parameters){
    		return !preg_match('/[^x00-x7F\-]/i', $value);
		});
		
		Validator::extend('text_required', function($attribute, $value, $parameters)
			{
				$value = preg_replace("/\s|&nbsp;/",'',$value);   
			    return strip_tags($value);
			});
		
		$sizeAllowed = $this->settings->file_size_allowed * 1024;
		$dimensions = explode('x',$this->settings->min_width_height_image);
		
		$messages = array (
		'photo.required' => trans('misc.please_select_image'),
		'categories_id.required' => trans('misc.please_select_category'),
		'description.required' => trans('misc.description_required'),
		'description.text_required' => trans('misc.text_required'),
		'goal.min' => trans('misc.amount_minimum', ['symbol' => $this->settings->currency_symbol, 'code' => $this->settings->currency_code]),
		'goal.max' => trans('misc.amount_maximum', ['symbol' => $this->settings->currency_symbol, 'code' => $this->settings->currency_code]),
        "photo.max"   => trans('misc.max_size').' '.Helper::formatBytes( $sizeAllowed, 1 ),
	);
		
		// Create Rules
		if( $id == null ) {
			return Validator::make($data, [
			'photo'           => 'required|mimes:jpg,gif,png,jpe,jpeg|image_size:>='.$dimensions[0].',>='.$dimensions[1].'|max:'.$this->settings->file_size_allowed.'',
        	'title'             => 'required|min:3|max:45',
        	'categories_id'  => 'required',
        	'goal'             => 'required|integer|max:'.$this->settings->max_campaign_amount.'|min:'.$this->settings->min_campaign_amount,
        	 'location'        => 'required|max:50',
            'description'  => 'text_required|required|min:20',	        
        ], $messages);
		
		// Update Rules
		} else {
			return Validator::make($data, [
				'photo'           => 'mimes:jpg,gif,png,jpe,jpeg|image_size:>='.$dimensions[0].',>='.$dimensions[1].'|max:'.$this->settings->file_size_allowed.'',
		    	'title'             => 'required|min:3|max:45',
		    	'categories_id'  => 'required',
		    	'goal'             => 'required|integer|max:'.$this->settings->max_campaign_amount.'|min:'.$this->settings->min_campaign_amount,
		    	 'location'        => 'required|max:50',
		        'description'  => 'required|min:20|text_required',
		        ], $messages);
		}
		
    }

	public function create() {
		
		// PATHS
		$temp            = 'public/temp/';
	    $path_small    = 'public/campaigns/small/'; 
		$path_large   = 'public/campaigns/large/';
		
		$input      = $this->request->all();
		$validator = $this->validator($input);
		
		 if ($validator->fails()) {
	        return response()->json([
			        'success' => false,
			        'errors' => $validator->getMessageBag()->toArray(),
			    ]); 
	    } //<-- Validator
	    
	    if( $this->request->hasFile('photo') )	{
	    	
			$extension    = $this->request->file('photo')->getClientOriginalExtension();
			$file_large     = strtolower(Auth::user()->id.time().str_random(40).'.'.$extension);
			$file_small     = strtolower(Auth::user()->id.time().str_random(40).'.'.$extension);
			
			if( $this->request->file('photo')->move($temp, $file_large) ) {
				
				set_time_limit(0);
				
				//=============== Image Large =================//
				$width  = Helper::getWidth( $temp.$file_large );
				$height = Helper::getHeight( $temp.$file_large );
				$max_width = '800';
				
				if( $width < $height ) {
					$max_width = '400';
				}
				
				if ( $width > $max_width ) {
					$scale = $max_width / $width;
					$uploaded = Helper::resizeImage( $temp.$file_large, $width, $height, $scale, $temp.$file_large );
				} else {
					$scale = 1;
					$uploaded = Helper::resizeImage( $temp.$file_large, $width, $height, $scale, $temp.$file_large );
				}
				
				//=============== Small Large =================//
				Helper::resizeImageFixed( $temp.$file_large, 400, 300, $temp.$file_small );
								
				//======= Copy Folder Small and Delete...
				if ( \File::exists($temp.$file_small) ) {
					\File::copy($temp.$file_small, $path_small.$file_small);
					\File::delete($temp.$file_small);
				}//<--- IF FILE EXISTS
				
				Image::make($temp.$file_large)->orientate();
				
				//======= Copy Folder Large and Delete...
				if ( \File::exists($temp.$file_large) ) {
					\File::copy($temp.$file_large, $path_large.$file_large);
					\File::delete($temp.$file_large);
				}//<--- IF FILE EXISTS
				
			}

			$image_small  = $file_small;
			$image_large  = $file_large; 
			
	    }//<====== End HasFile
	    
	    //<= HTML Clean
	    $configuration = [
	    'HTML.Allowed' => 'iframe[src|width|height],strong,em,a[href],ul,ol,li,br,img[src|width|height]',
	     "HTML.SafeIframe" => true,
	    "URI.SafeIframeRegexp" => "%^(http://|https://|//)(www.youtube.com/embed/|player.vimeo.com/video/)%",
	    ];
		
		$description = \Purify::clean($this->request->description,$configuration);
	    
	    //$description = html_entity_decode($_description);
		
		//REMOVE SCRIPT TAG
		//$description = Helper::removeTagScript($description);
					
		//REMOVE SCRIPT Iframe
		//$description = Helper::removeTagIframe($description);
	    
	    $description = trim(Helper::spaces($description));
		
		if( $this->settings->auto_approve_campaigns == '0' ) {
			$_status = 'pending';
		} else {
			$_status = 'active';
		}
		
	    $sql                        = new Campaigns;
		$sql->title                = trim($this->request->title);
		$sql->small_image   = $image_small;
		$sql->large_image   = $image_large;
		$sql->description     = Helper::removeBR($description);
		$sql->user_id          = Auth::user()->id;
		$sql->date               = Carbon::now();
		$sql->status             = $_status;
		$sql->token_id         = str_random(200);
		$sql->goal               = trim($this->request->goal);
		$sql->location          = trim($this->request->location);
		$sql->categories_id = $this->request->categories_id;
		$sql->deadline = $this->request->deadline;
		$sql->save();
		
		$id_campaign = $sql->id;
		
		if( $this->settings->auto_approve_campaigns == '0' ) {
			$_target = url('account/campaigns');
		} else {
			$_target = url('campaign',$id_campaign);
		}
	    
	    return response()->json([
				        'success' => true,
				        'target' => $_target,
				    ]);
		
	}//<<--- End Method

	public function view($id, $slug = null){
		
		$response = Campaigns::select('categories.name as category_name', 'campaigns.*')->where('campaigns.id',$id)
		                        ->where('campaigns.status','active')
	                            ->join('categories', 'categories.id', '=', 'campaigns.categories_id')
		                        ->firstOrFail();
		$uriCampaign = $this->request->path();
		
		if( str_slug( $response->title ) == '' ) {
				$slugUrl  = '';
			} else {
				$slugUrl  = '/'.str_slug( $response->title );
			}
			
			$url_campaign = 'campaign/'.$response->id.$slugUrl;
			
			//<<<-- * Redirect the user real page * -->>>
			$uriCanonical = $url_campaign;
			
			if( $uriCampaign != $uriCanonical ) {
				return redirect($uriCanonical);
			}
			
		return view('campaigns.view')->withResponse($response);
		
	}// End Method
	
	public function contactOrganizer() {
		
		$settings  = AdminSettings::first();
		
		$emailUser = User::find($this->request->id);
		
		if( $emailUser->email == '' ) {
			return response()->json([
				        'success' => false,
				        'error_fatal' => trans('misc.error'),
				    ]);
		}
	   	   
		$validator = Validator::make($this->request->all(), [
		'name'       => 'required|max:30',
		'email'       => 'required|email',
		'message'       => 'required|min:10',
	    	]);
				   
		   if ($validator->fails()) {
		        return response()->json([
				        'success' => false,
				        'errors' => $validator->getMessageBag()->toArray(),
				    ]);
		    }
		   
		   $sender = $settings->email_no_reply;
		   $replyTo = $this->request->email;
		   $user    = $this->request->name;
		   $titleSite = $settings->title;
		   $data = $this->request->message;
		   $_emailUser = $emailUser->email;
		   $_nameUser = $emailUser->name;
		   
		Mail::send('emails.contact-organizer', array( 'data' => $data ), 
		function($message) use ( $sender, $replyTo, $user, $titleSite, $_emailUser, $_nameUser)
			{
			    $message->from($sender, $titleSite)
			    	->to($_emailUser, $_nameUser)
			        ->replyTo($replyTo, $user)
					->subject( $titleSite.' - '.$user );
			});
			
			return response()->json([
				        'success' => true,
				        'msg' => trans('misc.msg_success'),
				    ]);
		
	}// End Method
	
	public function edit($id){
		
		$data = Campaigns::where('id', $this->request->id)
		->where('finalized', '0')
		->where('user_id', Auth::user()->id)
		->firstOrFail();
				
		return view('campaigns.edit')->withData($data);
	}//<---- End Method
	
		public function post_edit() {
		
		$sql = Campaigns::where('id',$this->request->id)->where('finalized','0')->first();

		if( !isset($sql) ) {
			return response()->json([
				        'fatalError' => true,
				         'target' => url('/'),
				    ]); 
					exit;
		}
			
		// PATHS
		$temp            = 'public/temp/';
	    $path_small    = 'public/campaigns/small/'; 
		$path_large   = 'public/campaigns/large/';
		
		// Old images
		$old_small     = $path_small.$sql->small_image;
		$old_large     = $path_large.$sql->large_image;
		
		$image_small  = $sql->small_image;
		$image_large  = $sql->large_image; 
		
		
		$input      = $this->request->all();
		$validator = $this->validator($input,$sql->id);
		
		 if ($validator->fails()) {
	        return response()->json([
			        'success' => false,
			        'errors' => $validator->getMessageBag()->toArray(),
			    ]); 
	    } //<-- Validator
	    
	    if( $this->request->hasFile('photo') )	{
	    	
			$extension    = $this->request->file('photo')->getClientOriginalExtension();
			$file_large     = strtolower(Auth::user()->id.time().str_random(40).'.'.$extension);
			$file_small     = strtolower(Auth::user()->id.time().str_random(40).'.'.$extension);
			
			if( $this->request->file('photo')->move($temp, $file_large) ) {
				
				set_time_limit(0);
				
				//=============== Image Large =================//
				$width  = Helper::getWidth( $temp.$file_large );
				$height = Helper::getHeight( $temp.$file_large );
				$max_width = '800';
				
				if( $width < $height ) {
					$max_width = '600';
				}
				
				if ( $width > $max_width ) {
					$scale = $max_width / $width;
					$uploaded = Helper::resizeImage( $temp.$file_large, $width, $height, $scale, $temp.$file_large );
				} else {
					$scale = 1;
					$uploaded = Helper::resizeImage( $temp.$file_large, $width, $height, $scale, $temp.$file_large );
				}
				
				//=============== Small Large =================//
				Helper::resizeImageFixed( $temp.$file_large, 400, 300, $temp.$file_small );
				
				//======= Copy Folder Small and Delete...
				if ( \File::exists($temp.$file_small) ) {
					\File::copy($temp.$file_small, $path_small.$file_small);
					\File::delete($temp.$file_small);
				}//<--- IF FILE EXISTS
				
				
				Image::make($temp.$file_large)->orientate();
				
				//======= Copy Folder Large and Delete...
				if ( \File::exists($temp.$file_large) ) {
					\File::copy($temp.$file_large, $path_large.$file_large);
					\File::delete($temp.$file_large);
				}//<--- IF FILE EXISTS
				
				// Delete Old Images
				\File::delete($old_large);
				\File::delete($old_small);
				
				$image_small  = $file_small;
			   $image_large  = $file_large; 
				
			}
			
	    }//<====== End HasFile
	    
	    if(  isset($this->request->finish_campaign) ) {
	    	$finish_campaign = '1';
			$endCampaign = true;
	    } else {
	    	$finish_campaign = '0';
			$endCampaign = false;
	    }
		
		//<= HTML Clean
	    $configuration = [
	    'HTML.Allowed' => 'iframe[src|width|height],strong,em,a[href],ul,ol,li,br,img[src|width|height]',
	     "HTML.SafeIframe" => true,
	    "URI.SafeIframeRegexp" => "%^(http://|https://|//)(www.youtube.com/embed/|player.vimeo.com/video/)%",
	    ];
		
		$description = \Purify::clean($this->request->description,$configuration);
		
		//$description = html_entity_decode($this->request->description);
		
		//REMOVE SCRIPT TAG
		//$description = Helper::removeTagScript($description);
					
		//REMOVE SCRIPT Iframe
		//$description = Helper::removeTagIframe($description);
	    
		$description = trim(Helper::spaces($description));
		
		$sql->title                = trim($this->request->title);
		$sql->small_image   = $image_small;
		$sql->large_image   = $image_large;
		$sql->description     = Helper::removeBR($description);
		$sql->user_id          = Auth::user()->id;
		$sql->goal               = trim($this->request->goal);
		$sql->location          = trim($this->request->location);
		$sql->finalized          = $finish_campaign;
		$sql->categories_id  = $this->request->categories_id;
		$sql->save();
		
		$id_campaign = $sql->id;
	    
	    return response()->json([
				        'success' => true,
				        'target' => url('campaign',$id_campaign),
				        'finish_campaign' => $endCampaign
				        
				    ]);
		
	}//<<--- End Method
	
	public function delete($id) {
		
		$data = Campaigns::where('id', $this->request->id)
		->where('user_id', Auth::user()->id)
		->firstOrFail();
		
		$path_small     = 'public/campaigns/small/'; 
		$path_large     = 'public/campaigns/large/';
		$path_updates = 'public/campaigns/updates/';
		
		$updates = $data->updates()->get();
		
		//Delete Updates
		foreach ($updates as $key) {
			
			if ( \File::exists($path_updates.$key->image) ) {
					\File::delete($path_updates.$key->image);
				}//<--- if file exists
				
				$key->delete();
		}//<-- 
		
		// Delete Campaign
		if ( \File::exists($path_small.$data->small_image) ) {
					\File::delete($path_small.$data->small_image);
				}//<--- if file exists
				
				if ( \File::exists($path_large.$data->large_image) ) {
					\File::delete($path_large.$data->large_image);
				}//<--- if file exists
		
		 $data->delete();
		 
		 return redirect('/');
				
	}//<<--- End Method
	
	
	public function update($id){
		
		$data = Campaigns::where('id', $this->request->id)
		->where('user_id', Auth::user()->id)
		->firstOrFail();
		
		return view('campaigns.update')->withData($data);
	}//<---- End Method
	
	public function post_update(){
		
		// PATHS
		$temp   = 'public/temp/';
		$path   = 'public/campaigns/updates/';
		
		$sizeAllowed = $this->settings->file_size_allowed * 1024;
		$dimensions = explode('x',$this->settings->min_width_height_image);
		
		$input      = $this->request->all();
		$validator = Validator::make($input, [
			'photo'           => 'mimes:jpg,gif,png,jpe,jpeg|image_size:>='.$dimensions[0].',>='.$dimensions[1].'|max:'.$this->settings->file_size_allowed.'',
            'description'  => 'required|min:20',	        
        ]);
		
		$image = '';
		
		 if ($validator->fails()) {
	        return response()->json([
			        'success' => false,
			        'errors' => $validator->getMessageBag()->toArray(),
			    ]); 
	    } //<-- Validator
	    
	    if( $this->request->hasFile('photo') )	{
	    	
			$extension    = $this->request->file('photo')->getClientOriginalExtension();
			$file     = strtolower(Auth::user()->id.time().str_random(40).'.'.$extension);
			
			if( $this->request->file('photo')->move($temp, $file) ) {
				
				set_time_limit(0);
				
				//=============== Image Large =================//
				$width  = Helper::getWidth( $temp.$file );
				$height = Helper::getHeight( $temp.$file );
				$max_width = '800';
				
				if( $width < $height ) {
					$max_width = '600';
				}
				
				if ( $width > $max_width ) {
					$scale = $max_width / $width;
					$uploaded = Helper::resizeImage( $temp.$file, $width, $height, $scale, $temp.$file );
				} else {
					$scale = 1;
					$uploaded = Helper::resizeImage( $temp.$file, $width, $height, $scale, $temp.$file );
				}
				
				Image::make($temp.$file)->orientate();
								
				//======= Copy Folder Small and Delete...
				if ( \File::exists($temp.$file) ) {
					\File::copy($temp.$file, $path.$file);
					\File::delete($temp.$file);
				}//<--- IF FILE EXISTS

				$image = $file;
			}			
			
	    }//<====== End HasFile
	    
	    $sql                        = new Updates;
		$sql->image           = $image;
		$sql->description     = trim(Helper::checkTextDb($this->request->description));
		$sql->campaigns_id = $this->request->id;
		$sql->date               = Carbon::now();
		$sql->token_id         = str_random(200);
		$sql->save();
			    	    
	    return response()->json([
				        'success' => true,
				        'target' => url('campaign',$this->request->id),
				    ]);
		
	}//<---- End Method
	
	public function edit_update($id){
			
		$data = Updates::where('id', $id)->firstOrFail();
		
		if(  $data->campaigns()->user_id != Auth::user()->id ){
			abort('404');
		}
		
		return view('campaigns.edit-update')->withData($data);
	}//<---- End Method
	
	public function post_edit_update(){
		
		$sql = Updates::find($this->request->id);
		
		// PATHS
		$temp   = 'public/temp/';
		$path   = 'public/campaigns/updates/';
		
	    $image = $sql->image;
		
		$sizeAllowed = $this->settings->file_size_allowed * 1024;
		$dimensions = explode('x',$this->settings->min_width_height_image);
		
		$input      = $this->request->all();
		$validator = Validator::make($input, [
			'photo'           => 'mimes:jpg,gif,png,jpe,jpeg|image_size:>='.$dimensions[0].',>='.$dimensions[1].'|max:'.$this->settings->file_size_allowed.'',
            'description'  => 'required|min:20',	        
        ]);
				
		 if ($validator->fails()) {
	        return response()->json([
			        'success' => false,
			        'errors' => $validator->getMessageBag()->toArray(),
			    ]); 
	    } //<-- Validator
	    
	    if( $this->request->hasFile('photo') )	{
	    	
			$extension    = $this->request->file('photo')->getClientOriginalExtension();
			$file     = strtolower(Auth::user()->id.time().str_random(40).'.'.$extension);
			
			if( $this->request->file('photo')->move($temp, $file) ) {
				
				set_time_limit(0);
				
				//=============== Image Large =================//
				$width  = Helper::getWidth( $temp.$file );
				$height = Helper::getHeight( $temp.$file );
				$max_width = '800';
				
				if( $width < $height ) {
					$max_width = '600';
				}
				
				if ( $width > $max_width ) {
					$scale = $max_width / $width;
					$uploaded = Helper::resizeImage( $temp.$file, $width, $height, $scale, $temp.$file );
				} else {
					$scale = 1;
					$uploaded = Helper::resizeImage( $temp.$file, $width, $height, $scale, $temp.$file );
				}
				
				Image::make($temp.$file)->orientate();
								
				//======= Copy Folder Small and Delete...
				if ( \File::exists($temp.$file) ) {
					\File::copy($temp.$file, $path.$file);
					\File::delete($temp.$file);
				}//<--- IF FILE EXISTS
				
				// Delete Old Images
				if( \File::exists($path.$sql->image) ) {
					\File::delete($path.$sql->image);
				}
				
				$image = $file;
			}
			
	    }//<====== End HasFile
	    
		$sql->image        = $image;
		$sql->description = trim(Helper::checkTextDb($this->request->description));
		$sql->save();
			    	    
	    return response()->json([
				        'success' => true,
				        'target' => url('campaign',$sql->campaigns_id),
				    ]);
		
	}//<---- End Method
	
	
	public function delete_image_update(){
		
		$res = Campaigns::where('id', $this->request->id)
		->where('user_id', Auth::user()->id)
		->first();
		
		$path = 'public/campaigns/updates/';
		
		$data = Updates::where('id', $this->request->id)->first();
		
		if( isset( $data ) ) {
			if ( \File::exists($path.$data->image) ) {
					\File::delete($path.$data->image);
				}//<--- IF FILE EXISTS
				
			$data->image = '';
			$data->save();
		}
		
}//<--- End Method

	public function withdrawal(){
		
		if( Auth::user()->payment_gateway == 'Paypal' 
		&& empty(  Auth::user()->paypal_account  ) 
		
		|| Auth::user()->payment_gateway == 'Bank'
		&& empty(  Auth::user()->bank  )
		
		|| empty(Auth::user()->payment_gateway)
		
		) {
			\Session::flash('notification',trans('misc.configure_withdrawal_method'));
			return redirect('account/campaigns');
		}
		
		$res = Campaigns::where('id', $this->request->id)
		->where('user_id', Auth::user()->id)
		->first();
		
		$WithdrawalsExists = Withdrawals::where('campaigns_id', $this->request->id)->first();		
		
		if(  !empty($res) && empty($WithdrawalsExists) ) {
		   
		   if(  $this->settings->payment_gateway == 'Paypal' ) {
			$fee       = config('commissions.paypal_fee');
			$cents   =  config('commissions.paypal_cents');
		}  elseif(  $this->settings->payment_gateway == 'Stripe' ) {
			$fee      = config('commissions.stripe_fee');
			$cents   =  config('commissions.stripe_cents');
		}
	
		   $amount = $res->donations()->sum('donation');
		   $_funds  = $amount - (  $amount * $fee/100 ) - $cents; // Fee processor
		   $funds    = $_funds - (  $_funds * $this->settings->fee_donation/100  ); // Fee Site
		   
		   if( Auth::user()->payment_gateway == 'Paypal' ) {
		   	$_account = Auth::user()->paypal_account;
		   } else {
		   	$_account = Auth::user()->bank;
		   }
	  
			$sql                         = new Withdrawals;
			$sql->campaigns_id  = $res->id;
			$sql->amount           = number_format($funds, 2);
			$sql->gateway           = Auth::user()->payment_gateway;
			$sql->account           = $_account;
			$sql->save();
			
			return redirect('account/withdrawals');
			
		} else {
			return redirect('account/campaigns');
		}
		
	}//<--- End Method
	
	public function show_withdrawal() {
    	
		
		$data = Withdrawals::leftJoin('campaigns', function($join) {
      $join->on('withdrawals.campaigns_id', '=', 'campaigns.id');
    })
	    ->where('campaigns.user_id',Auth::user()->id)
		->select('withdrawals.*')
		->addSelect('campaigns.title')
		->orderBy('withdrawals.id','DESC')
	    ->paginate(20);
		
    	return view('users.withdrawals')->withData($data);
		
    }//<--- End Method
    
    public function withdrawalConfigure() {


		if( $this->request->type != 'paypal' && $this->request->type != 'bank' ) {
			\Session::flash('error', trans('misc.error'));
			return redirect('account/withdrawals/configure');
			exit;
		}
		
		// Validate Email Paypal
		if( $this->request->type == 'paypal') {
			$rules = array(
	        'email_paypal'             => 'required|email|confirmed',
        );
		
		$this->validate($this->request, $rules);
		
		$user = User::find(Auth::user()->id);
		$user->paypal_account = $this->request->email_paypal;
		$user->payment_gateway = 'Paypal';
		$user->save();
		
		\Session::flash('success', trans('admin.success_update'));
		return redirect('account/withdrawals/configure');
		
		}// Validate Email Paypal
		
		elseif($this->request->type == 'bank') {
			
			$rules = array(
	        'bank'             => 'required',
       		 );
		
		  $this->validate($this->request, $rules);
		
		   $user = User::find(Auth::user()->id);
		   $user->bank = $this->request->bank;
		   $user->payment_gateway = 'Bank';
		   $user->save();
			
			\Session::flash('success', trans('admin.success_update'));
			return redirect('account/withdrawals/configure');
		}
		
    }//<--- End Method
    
    public function withdrawalDelete(){
    	
		$withdrawal = Withdrawals::find($this->request->id);
		
		if(  isset( $withdrawal ) ) {
			
			$Campaigns = Campaigns::where('id',$withdrawal->campaigns_id)
			->where('user_id', Auth::user()->id)
			->first();
			
			if( isset( $Campaigns ) ) {
				
				$withdrawal->delete();
				return redirect('account/withdrawals');
			}

		}// Isset withdrawal
    	
    }//<--- End Method
    
    public function report(){
    	
		if( $this->request->user == Auth::user()->id ) {
			return redirect('/');
		}
				
		$data = CampaignsReported::firstOrNew(['user_id' => Auth::user()->id, 'campaigns_id' => $this->request->id]);
		
		if( $data->exists ) {
			\Session::flash('noty_error','error');
			return redirect()->back();
		} else {
			
			$data->save();
			\Session::flash('noty_success','success');
			return redirect()->back();
		}
		
	}//<--- End Method
		
	
}
