<?php
 // the public key of the application in the discord dev portal
$publickey = hex2bin("MY_APP_PUBLIC_KEY_HERE");
// a bot token as "Bot [mytoken]" - a client credentials token as "Bearer [mytoken]" *might* work, depending on if it can read thread details
$token = "Bot MY_BOT_TOKEN_HERE";
// lookup table of forum channel ID -> webhook URL
$webhooklookup = array(
	'MY_CHANNEL_ID_HERE' => 'https://discord.com/api/webhooks/MY_WEBHOOK_ID_HERE/MY_WEBHOOK_TOKEN_HERE'
);
// a prefix for the avatar URLs, for randomly generating avatars. the SHA-1 of the user will be appended to this
$avatarprefix = "https://api.dicebear.com/5.x/identicon/png?seed=";
// a secret key, part of user hash generation
$secretkey = "RANDOM_GENERATED_SECRET_KEY_PLEASE_CHANGE_THIS_TO_ENSURE_ANONYMITY";
// enables using random messages
$enablerandom = true;
// random messages to use on successful message send, as a funny
$randommessages = array(
	"Why did you post that?",
	"Good post, my friend."
);
// the frequency at which the random messages will be added, 0 - 100%, 1 - 50%, 2 - 33%, 3 - 25%, etc
$randomfreq = 3;

// -- CODE STARTS HERE --

// helper function for making a JSON POST request
function make_json_post($url, $array) {
	$options = array(
		'http' => array(
			'header'  => "Content-type: application/json\r\n",
			'method'  => 'POST',
			'content' => json_encode($array)
		)
	);
	$context = stream_context_create($options);
	return file_get_contents($url, false, $context);	
}
// helper function for making an authenticated GET request
function make_authed_get($url, $token) {
	$options = array(
		'http' => array(
			'header'  => "Authorization: $token\r\n",
			'method'  => 'GET',
		)
	);
	$context = stream_context_create($options);
	return file_get_contents($url, false, $context);	
}

// set up some variables
$signature = hex2bin($_SERVER['HTTP_X_SIGNATURE_ED25519']);
if ($signature === false) {
	die(http_response_code(403));
}
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'];
$postdata = file_get_contents('php://input');

// signature verification
$data_to_verify = $timestamp . $postdata;

if (sodium_crypto_sign_verify_detached($signature, $data_to_verify, $publickey) !== true) {
	die(http_response_code(403));
}

// decode the json sent by discord
$jsondata = json_decode($postdata, true);

// make sure discord knows we're gonna return with a json blob
header("Content-Type: application/json");

// check if we're recieving a slash command
if ($jsondata["type"] == 2) {
	// check if the command name is the "anon" command
	if ($jsondata["data"]["name"] == "anon") {
		// create a hash of the thread id + user id for 4-letter ID and picture generation
		$uhs = sha1($jsondata["channel_id"] . $jsondata["member"]["user"]["id"] . $secretkey);
		
		// fetch the data about the thread's channel itself, since discord doesn't send that in the interaction request
		// this is inefficient but..what can ya do?
		$channeldata = json_decode(make_authed_get("https://discord.com/api/v10/channels/" . $jsondata["channel_id"], $token), true);
		if (array_key_exists($channeldata["parent_id"], $webhooklookup) === false) {
			$resp = array(
				'type' => 4,
				'data' => array(
					'content' => 'Anonymous messages are only allowed in threads.',
					'flags' => 64
				)
			);
			die(json_encode($resp));
		}
		// fetch the webhook's URL 
		$webhookurl = $webhooklookup[$channeldata["parent_id"]];
		
		// create the webhook request
		$webhook_post = array(
			'username' => 'Anon ' . substr($uhs, 4, 4),
			'avatar_url' => $avatarprefix . $uhs,
			'content' => $jsondata["data"]["options"][0]["value"]
		);
		if (make_json_post($webhookurl . "?thread_id=" . $jsondata["channel_id"], $webhook_post) === false) {
			// server error if sending the webhook failed :fearful:
			die(http_response_code(500));
		}
		
		// choose a random message
		if ($enablerandom == true && rand(0, $randomfreq) == 0) {
			$msgno = rand(0,count($randommessages)-1);
			$randommsg = $randommessages[$msgno];
		} else {
			$randommsg = "";
		}
		
		// return an ephemeral response message
		// it would be ideal if we could just forego this entirely and have it seamless
		$resp = array(
			'type' => 4,
			'data' => array(
				'content' => "Anonymous message posted. $randommsg",
				'flags' => 64
			)
		);
		echo json_encode($resp);
	}
} else { // probably a ping, unless the bot was configured incorrectly
	$resp = array('type' => 1); // default response, "pong"
	echo json_encode($resp);
}
?>
