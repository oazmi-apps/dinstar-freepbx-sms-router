// why do we need to manually compute the digest authentication header? because curl fails to do it correctly with our dinstar sim gateway.
// basically, the _digest_ authentication requires _two_ requests:
// 1. querying the server what kind of security challenge it wants us to complete to authenticate.
// 2. computing the authentication token (aka the digest), and then sending the request to the sim gateway.
//
// the problem is, when a POST request is to be made (with some body), curl makes the first query request as POST with an empty body.
// this causes the gateway to close the tcp connection without responding; causing curl to just wait forever.
// the correct way for communicating involves sending a GET request for the initial challenge query,
// and then sending the POST request once we compute the digest token with our username and password.

// import { createHash } from "node:crypto"
const { createHash } = await import("node:crypto")

interface DigestChallenge {
	algorithm: string // usually md5.
	realm: string
	nonce: string
	qop: string
}

interface SmsMessage {
	from: string
	to: string
	port: number // this is the sim port number that will send out the message.
	text: string
}

function computeDigest(user: string, password: string, pathname: string, challenge: DigestChallenge, method: "GET" | "POST" | "PUT"): string {
	const
		{ realm, nonce, qop, algorithm } = challenge,
		nc = "00000001", // nonce count
		cnonce = Math.random().toString(36).substring(2, 12) // the client's nonce

	// hash1 = md5(username:realm:password)
	const hash1 = createHash(algorithm)
		.update(`${user}:${realm}:${password}`)
		.digest("hex")
	// hash2 = md5(method:digest_pathname)
	const hash2 = createHash(algorithm)
		.update(`${method}:${pathname}`)
		.digest("hex")
	// hash3 = response to challenge = md5(hash1:nonce:nc:cnonce:qop:hash2)
	const hash3 = createHash(algorithm)
		.update(`${hash1}:${nonce}:${nc}:${cnonce}:${qop}:${hash2}`)
		.digest("hex")
	return `Digest username="${user}", realm="${realm}", nonce="${nonce}", uri="${pathname}", qop=${qop}, nc=${nc}, cnonce="${cnonce}", response="${hash3}"`
}

/**
 * example usage:
 * 
 * ```ts
 * const input = `name="Seto Kaiba", age="28", city="Domino City, Japan", profession="former \\"king of games\\""`
 * const output = parseCommaSeparatedKV(input)
 * console.log(output)
 * // prints: `{ name: "Seto Kaiba", age: "28", city: "Domino City, Japan", profession: "former \"king of games\"" }`
 * ```
*/
const parseCommaSeparatedKV = (text: string): object => {
	const
		// the regex to find all key="value" pairs.
		regex = /(\w+)="((?:\\.|[^"])*)"/g,
		kv_entries: Array<[string, string]> = []
	let match
	// iterate over all matches.
	while ((match = regex.exec(text)) !== null) {
		// match[1] is the key, match[2] is the value inside quotes.
		const
			[, key, value] = match,
			// we also unescape any internal quotes (i.e. `\\"` will become `\"`).
			unescaped_value = value.replace(/\\"/g, `"`)
		kv_entries.push([key, unescaped_value])
	}
	// convert the array of pairs into a json object.
	return Object.fromEntries(kv_entries)
}

/**
 * example usage:
 * 
 * ```ts
 * const digest_header_string = `Digest realm="Web Server", domain="",qop="auth", nonce="2667ca7ba60da049ca03170247e563c3", opaque="5ccc069c403ebaf9f0171e9517f40e41",algorithm="MD5", stale="FALSE"`
 * const challenge = parseDigestChallengeText(digest_header_string);
 * console.log(challenge);
 * // prints: `{ realm: "Web Server", nonce: "2667ca7ba60da049ca03170247e563c3", qop: "auth", algorithm: "md5" }`
 * ```
*/
function parseDigestChallengeText(text: string): DigestChallenge {
	const { realm, nonce, qop, algorithm } = parseCommaSeparatedKV(text.replace(/^digest/i, "")) as Record<string, string>
	return { realm, nonce, qop, algorithm: algorithm.toLowerCase() }
}

async function getGatewayDigestAuth(url: string, username: string, password: string): Promise<string> {
	const initial_challenge_resp = await fetch(url)
	if (initial_challenge_resp.status != 401) { throw new Error("[getGatewayDigestAuth]: expected first reposnse to be 401 (unauthorized).") }
	const challenge_text = initial_challenge_resp.headers.get("www-authenticate")
	if (!challenge_text) { throw new Error(`[getGatewayDigestAuth]: expected a digest with challenge text, but got: "${challenge_text}" instead.`) }
	const challenge = parseDigestChallengeText(challenge_text)
	return computeDigest(username, password, new URL(url).pathname, challenge, "POST")
}

async function sendSms(username: string, password: string, sms: SmsMessage): Promise<boolean> {
	const
		url = "http://192.168.86.245/api/send_sms",
		digest_auth_header = await getGatewayDigestAuth(url, username, password),
		response = await fetch(url, {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"Authorization": digest_auth_header,
			},
			body: JSON.stringify({
				text: sms.text,
				param: [{ number: sms.to }],
				port: [sms.port],
				encoding: "unicode",
			}),
		})
	if (!response.ok) { throw new Error(`[sendSms]: expected to receive http-code 202. instead got: ${response.status} ${response.statusText}`) }
	return true
}

// sample test
const digest_auth = await sendSms("admin", "admin123", {
	from: "+1122334455",
	to: "+16315554444",
	port: 0,
	text: "hello world!!!!!!",
})

console.log(digest_auth)
