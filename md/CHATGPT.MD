# Files Connected to chatgpt.js

## Frontend Files
1. `js/chatgpt.js` - The main JavaScript file for ChatGPT functionality
2. `js/config.js` - Contains pricing configuration and API settings
3. `js/machineConfig.js` - Handles machine ID and folder mapping
4. `pages/chatgpt.html` - The HTML interface for the ChatGPT prompt page
5. `pages/edit_prompt.html` - Referenced in chatgpt.js for redirection after payment
6. `css/style.css` - Styling used by the ChatGPT interface

## Backend Files
7. `check_payment.php` - Verifies if payment has been made
8. `get_cost_details.php` - Retrieves cost details for the ChatGPT request
9. `save_cost.php` - Saves cost information for the current request
10. `save_prompt.php` - Saves the prompt and generates a response using OpenAI API
11. `includes/GoogleDriveHandler.php` - Handles file operations with Google Drive

## Data Files (Generated)
12. `temp_uploads/machine_[ID]/prompt.txt` - Stores the user's prompt
13. `temp_uploads/machine_[ID]/response.txt` - Stores the ChatGPT response
14. `temp_uploads/machine_[ID]/cost.txt` - Contains cost information
15. `temp_uploads/machine_[ID]/cost_details.json` - Detailed cost breakdown
16. `temp_uploads/machine_[ID]/Amount_request.txt` - Payment amount request
17. `temp_uploads/machine_[ID]/Amount_paid.txt` - Confirmation of payment
