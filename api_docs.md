# Audio Search API Documentation

This API allows you to search and retrieve voice call recordings from Google Drive based on a phone number and an optional date range. 

**Key Features:**
- Uses **Pure PHP (No Composer)** for lightweight deployment.
- Authenticates with Google Drive using a Service Account JSON key (`service-account.json`).
- Intelligently queries Google Drive taking into account Google's prefix tokenization rules (e.g. supporting `%2B` or `+` formatted prefixes).
- Parses specific PBX filename conventions to extract caller, receiver, and call direction.

## Endpoint
`GET /api_search_audio.php`

## Authentication

This API is protected and requires authentication via a secret token. The token is defined in your `config.php` file under `$config['api']['auth_token']`.

You can pass the token in one of two ways:

### 1. HTTP Header (Recommended)
Pass the token using the standard Bearer token format:
```http
Authorization: Bearer YOUR_SECRET_TOKEN
```

### 2. Query Parameter
Pass the token directly in the URL (useful for quick testing in a browser):
```http
GET /api_search_audio.php?phone=0945547598&token=YOUR_SECRET_TOKEN
```

---

## Request Parameters

All parameters should be passed in the query string (`GET`).

| Parameter | Type | Required | Description |
| :--- | :--- | :---: | :--- |
| `phone` | `string` | **Yes** | The phone number to search for (e.g., `0945547598`, `+66945547598`, `02-123-4567`). The API automatically sanitizes the input by removing spaces/dashes and converting Thai formats. |
| `date_start` | `string` | No | Start date for filtering results. Must be in exactly `YYYY-MM-DD` format. |
| `date_end` | `string` | No | End date for filtering results. Must be in exactly `YYYY-MM-DD` format. |

---

## Response Format

The API returns a JSON response. 

### Success Response (200 OK)
```json
{
    "success": true,
    "count": 2,
    "data": [
        {
            "id": "RZIJ",
            "date": "2026-05-24",
            "time": "14:49:33",
            "caller": "+66945547598",
            "receiver": "+66639711435",
            "direction": "IN",
            "filename": "20260524_144933_RZIJ-%2B66945547598-%2B66639711435-IN.wav",
            "size": 9216,
            "fileId": "17GwY7yxD11qqeTcR5QsTUpT6rdFkjVVg",
            "link": "https://drive.google.com/file/d/17GwY7yxD11qqeTcR5QsTUpT6rdFkjVVg/view"
        },
        {
            "id": "20260226094814",
            "date": "2026-02-26",
            "time": "09:48:14",
            "caller": "+66945547598",
            "receiver": "+66878372158",
            "direction": "OUT",
            "filename": "myrecordings_20260226_094814_out_+66945547598_+66878372158.wav",
            "size": 150230,
            "fileId": "1hT4yxRs47KC09Tld8JaUoze9MBu_1192",
            "link": "https://drive.google.com/file/d/1hT4yxRs47KC09Tld8JaUoze9MBu_1192/view"
        }
    ]
}
```

### Error Responses

**400 Bad Request** (Missing phone or invalid format)
```json
{
    "success": false,
    "message": "Missing phone parameter"
}
```

**401 Unauthorized** (Missing or invalid token)
```json
{
    "success": false,
    "message": "Unauthorized: Invalid or missing token"
}
```

**500 Internal Server Error** (Config missing or Google API error)
```json
{
    "success": false,
    "message": "Configuration file missing"
}
```

---

## Example Usage

### Using cURL (with Header)
```bash
curl -X GET "http://voicecall.test/api_search_audio.php?phone=0945547598&date_start=2026-01-01" \
     -H "Authorization: Bearer voicecall_secret_token_2026"
```

### Using JavaScript (Fetch API)
```javascript
const searchPhone = "0945547598";
const token = "voicecall_secret_token_2026";

fetch(`http://voicecall.test/api_search_audio.php?phone=${searchPhone}`, {
    method: 'GET',
    headers: {
        'Authorization': `Bearer ${token}`
    }
})
.then(response => response.json())
.then(data => console.log(data))
.catch(error => console.error('Error:', error));
```
