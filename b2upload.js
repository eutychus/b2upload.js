/*
	b2upload.js
	2019 Brad Murray
	for use with https://github.com/eutychus/resumable.js
*/

// by default, this uses webcrypto for sha1, so it requires a modern browser (NO IE)
// you should be able to replace this with sha1 from code.google.com/p/crypto-js
// TODO: Proper handling of failed files

async function sha1_webcrypto(message) {
	const hashBuffer = await crypto.subtle.digest('SHA-1', message);
	const hashArray = Array.from(new Uint8Array(hashBuffer));
	const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
	return hashHex;
}

function sha1_webcrypto_file(fileObj, callback) {
	var reader = new FileReader();
	reader.onload = function () {
		sha1_webcrypto(reader.result).then(callback);
	}
	reader.readAsArrayBuffer(fileObj);
}


var b2Upload = {
	"multiMinSize": 5000000,
	"uploadInfo":  {},
	"preprocessFile": function(rf) {

		var formData = new FormData();
		formData.append("b2LastModified", rf.file.lastModified);
		formData.append("b2FileName", rf.file.name);
		formData.append("b2FileSize", rf.file.size);
		formData.append("action", "authFile");

		var xhr = new XMLHttpRequest();
		xhr.responseType = "json"
		xhr.open('POST', "api.php", true);
		xhr.addEventListener('loadend', function(evt) {
			console.log(["loadend", evt, xhr]);
			if(xhr.status == 200 && "authorizationToken" in xhr.response) {
				b2Upload.uploadInfo[rf.file.uniqueIdentifier] = xhr.response;
				rf.preprocessFinished();
			}
			else {
				console.log("Error getting upload credentials");
			}
		}, false);
		xhr.send(formData);

	},

	"postprocessFile": function(rf, message) {
		var formData = new FormData();
		formData.append("fileId", b2Upload.uploadInfo[rf.file.uniqueIdentifier].fileId);
		formData.append("action", "finishFile");
		formData.append("fileSize", rf.file.size);

		var xhr = new XMLHttpRequest();
		xhr.responseType = "json"
		xhr.open('POST', "api.php", true);
		xhr.addEventListener('loadend', function(evt) {
			console.log(["loadend", evt, xhr]);
		}, false);
		xhr.send(formData);
	},

	"preprocessChunk": function(chunk) {
		console.log(["preprocessChunk called", chunk]);
		var uid = chunk.fileObj.file.uniqueIdentifier;

		sha1_webcrypto_file(chunk.fileObj.file.slice(chunk.startByte, chunk.endByte), function(hash) {
			b2Upload.uploadInfo[uid].digest = hash;
			chunk.preprocessFinished();
		});
	},

	"customHeaders": function(rf, chunk) {
		var uid = rf.file.uniqueIdentifier;
		var info = b2Upload.uploadInfo[uid];
		var headers = {
			"Authorization": info.authorizationToken,
			"X-Bz-Part-Number": chunk.offset+1,
			"X-Bz-Content-Sha1": info.digest
		};
		return headers;
	},

	"customTarget": function(params, chunk) {
		var uid = chunk.fileObj.uniqueIdentifier;
		return b2Upload.uploadInfo[uid].uploadUrl;
	},

	"customTestTarget": function(params, chunk) {
		var info = b2Upload.uploadInfo[chunk.fileObj.uniqueIdentifier];
		return "api.php?action=checkFile&fileId="+info.fileId;
	}

};
